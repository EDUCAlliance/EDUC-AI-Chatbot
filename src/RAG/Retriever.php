<?php
namespace EDUC\RAG;

use EDUC\API\LLMClient;
use EDUC\Database\EmbeddingRepository;

class Retriever {
    private LLMClient $llmClient;
    private EmbeddingRepository $embeddingRepository;
    private int $topK;
    
    public function __construct(
        LLMClient $llmClient, 
        EmbeddingRepository $embeddingRepository,
        int $topK = 5
    ) {
        $this->llmClient = $llmClient;
        $this->embeddingRepository = $embeddingRepository;
        $this->topK = $topK;
    }
    
    public function retrieveRelevantContent(string $query): array {
        // Generate embedding for the query
        $queryEmbeddingResult = $this->llmClient->generateEmbedding($query);
        
        if (!$queryEmbeddingResult['success']) {
            error_log("Error generating embedding for query: " . ($queryEmbeddingResult['error'] ?? 'Unknown error'));
            return [
                'success' => false,
                'error' => $queryEmbeddingResult['error'] ?? 'Unknown error generating query embedding'
            ];
        }
        
        // Search for similar embeddings
        $similarEmbeddings = $this->embeddingRepository->searchSimilarEmbeddings(
            $queryEmbeddingResult['embedding'],
            $this->topK
        );
        
        if (empty($similarEmbeddings)) {
            return [
                'success' => true,
                'matches' => [],
                'content' => "",
                'debug' => [
                    'query' => $query,
                    'embedding_model' => $queryEmbeddingResult['model'] ?? 'unknown',
                    'top_k' => $this->topK,
                    'matches_found' => 0
                ]
            ];
        }
        
        // Extract content from the matches
        $contents = array_map(function($match) {
            return $match['content'];
        }, $similarEmbeddings);
        
        // Combine content into a single string
        $combinedContent = implode("\n\n", $contents);
        
        // Enhanced debug information
        $matchesInfo = array_map(function($match) {
            return [
                'document_id' => $match['document_id'],
                'similarity' => $match['similarity'],
                'content_preview' => mb_substr($match['content'], 0, 150) . (mb_strlen($match['content']) > 150 ? '...' : ''),
                'content_length' => mb_strlen($match['content']),
                'metadata' => $match['metadata'] ?? []
            ];
        }, $similarEmbeddings);
        
        return [
            'success' => true,
            'matches' => $similarEmbeddings,
            'content' => $combinedContent,
            'debug' => [
                'query' => $query,
                'embedding_model' => $queryEmbeddingResult['model'] ?? 'unknown',
                'top_k' => $this->topK,
                'matches_found' => count($similarEmbeddings),
                'matches_info' => $matchesInfo,
                'total_content_length' => mb_strlen($combinedContent)
            ]
        ];
    }
    
    public function augmentPrompt(string $systemPrompt, string $query, array $options = []): string {
        $retrievalResult = $this->retrieveRelevantContent($query);
        
        if (!$retrievalResult['success'] || empty($retrievalResult['matches'])) {
            return $systemPrompt;
        }
        
        $contextHeader = $options['context_header'] ?? "### Relevant Context:";
        $contextFooter = $options['context_footer'] ?? "### End Context\n\nPlease use the above context to help answer the user's query:";
        
        $augmentedPrompt = $systemPrompt . "\n\n" . $contextHeader . "\n\n" . $retrievalResult['content'] . "\n\n" . $contextFooter;
        
        return $augmentedPrompt;
    }
} 