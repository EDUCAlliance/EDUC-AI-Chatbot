<?php

namespace EDUC\RAG;

use Exception;
use EDUC\API\LLMClient;
use EDUC\Database\EmbeddingRepository;
use EDUC\Utils\Logger;

/**
 * RAG Retriever for semantic search and content retrieval
 */
class Retriever {
    private LLMClient $llmClient;
    private EmbeddingRepository $embeddingRepository;
    private int $topK;
    private string $embeddingModel;
    
    public function __construct(
        LLMClient $llmClient,
        EmbeddingRepository $embeddingRepository,
        int $topK = 5,
        string $embeddingModel = 'e5-mistral-7b-instruct'
    ) {
        $this->llmClient = $llmClient;
        $this->embeddingRepository = $embeddingRepository;
        $this->topK = $topK;
        $this->embeddingModel = $embeddingModel;
    }
    
    /**
     * Retrieve relevant content for a given query
     */
    public function retrieveRelevantContent(string $query): string {
        try {
            Logger::debug('Retrieving relevant content', [
                'query' => $query,
                'top_k' => $this->topK
            ]);
            
            // Generate embedding for the query
            $queryEmbedding = $this->generateQueryEmbedding($query);
            
            // Search for similar content
            $similarContent = $this->embeddingRepository->findSimilarContent(
                $queryEmbedding,
                $this->topK
            );
            
            if (empty($similarContent)) {
                Logger::debug('No similar content found for query');
                return '';
            }
            
            // Format and rank the retrieved content
            $formattedContent = $this->formatRetrievedContent($similarContent, $query);
            
            Logger::debug('Content retrieved successfully', [
                'results_count' => count($similarContent),
                'formatted_length' => strlen($formattedContent)
            ]);
            
            return $formattedContent;
            
        } catch (Exception $e) {
            Logger::error('Error retrieving content', [
                'error' => $e->getMessage(),
                'query' => $query
            ]);
            return '';
        }
    }
    
    /**
     * Generate embedding for a query
     */
    private function generateQueryEmbedding(string $query): array {
        $response = $this->llmClient->generateEmbeddings($query, $this->embeddingModel);
        
        if (!isset($response['data'][0]['embedding'])) {
            throw new Exception('Invalid embedding response format');
        }
        
        return $response['data'][0]['embedding'];
    }
    
    /**
     * Format retrieved content for context injection
     */
    private function formatRetrievedContent(array $similarContent, string $query): string {
        $contextParts = [];
        
        foreach ($similarContent as $index => $item) {
            $content = $item['content'];
            $similarity = 1 - ($item['distance'] ?? 0); // Convert distance to similarity
            
            // Add metadata if available
            $metadata = json_decode($item['metadata'] ?? '{}', true);
            $source = $metadata['source'] ?? 'Document';
            
            // Format the content piece
            $contextPart = "--- {$source} (Relevance: " . number_format($similarity * 100, 1) . "%) ---\n";
            $contextPart .= $this->cleanAndTruncateContent($content) . "\n";
            
            $contextParts[] = $contextPart;
        }
        
        return implode("\n", $contextParts);
    }
    
    /**
     * Clean and truncate content for context
     */
    private function cleanAndTruncateContent(string $content, int $maxLength = 500): string {
        // Remove excessive whitespace and newlines
        $content = preg_replace('/\s+/', ' ', trim($content));
        
        // Truncate if too long
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength);
            
            // Try to end at a word boundary
            $lastSpace = strrpos($content, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
                $content = substr($content, 0, $lastSpace);
            }
            
            $content .= '...';
        }
        
        return $content;
    }
    
    /**
     * Retrieve content with additional filtering
     */
    public function retrieveWithFilters(
        string $query,
        array $filters = [],
        int $customTopK = null
    ): string {
        $topK = $customTopK ?? $this->topK;
        
        try {
            // Generate embedding for the query
            $queryEmbedding = $this->generateQueryEmbedding($query);
            
            // Search with filters
            $similarContent = $this->embeddingRepository->findSimilarContentWithFilters(
                $queryEmbedding,
                $topK,
                $filters
            );
            
            return $this->formatRetrievedContent($similarContent, $query);
            
        } catch (Exception $e) {
            Logger::error('Error retrieving filtered content', [
                'error' => $e->getMessage(),
                'query' => $query,
                'filters' => $filters
            ]);
            return '';
        }
    }
    
    /**
     * Retrieve content by document ID
     */
    public function retrieveFromDocument(string $query, int $documentId): string {
        try {
            // Generate embedding for the query
            $queryEmbedding = $this->generateQueryEmbedding($query);
            
            // Search within specific document
            $similarContent = $this->embeddingRepository->findSimilarContentInDocument(
                $queryEmbedding,
                $documentId,
                $this->topK
            );
            
            return $this->formatRetrievedContent($similarContent, $query);
            
        } catch (Exception $e) {
            Logger::error('Error retrieving content from document', [
                'error' => $e->getMessage(),
                'query' => $query,
                'document_id' => $documentId
            ]);
            return '';
        }
    }
    
    /**
     * Get content statistics
     */
    public function getContentStats(): array {
        return [
            'total_embeddings' => $this->embeddingRepository->getTotalEmbeddingsCount(),
            'total_documents' => $this->embeddingRepository->getTotalDocumentsCount(),
            'avg_embeddings_per_document' => $this->embeddingRepository->getAverageEmbeddingsPerDocument(),
            'embedding_model' => $this->embeddingModel,
            'top_k' => $this->topK
        ];
    }
    
    /**
     * Test retrieval with a sample query
     */
    public function testRetrieval(string $query = 'test query'): array {
        try {
            $startTime = microtime(true);
            
            $content = $this->retrieveRelevantContent($query);
            
            $endTime = microtime(true);
            $processingTime = ($endTime - $startTime) * 1000; // milliseconds
            
            return [
                'success' => true,
                'query' => $query,
                'content_length' => strlen($content),
                'processing_time_ms' => round($processingTime, 2),
                'has_content' => !empty($content)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'query' => $query
            ];
        }
    }
    
    /**
     * Set the number of top results to retrieve
     */
    public function setTopK(int $topK): void {
        $this->topK = $topK;
    }
    
    /**
     * Get the current top K setting
     */
    public function getTopK(): int {
        return $this->topK;
    }
    
    /**
     * Set the embedding model
     */
    public function setEmbeddingModel(string $model): void {
        $this->embeddingModel = $model;
    }
    
    /**
     * Get the current embedding model
     */
    public function getEmbeddingModel(): string {
        return $this->embeddingModel;
    }
}
?> 