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
        error_log("DEBUG - Generating embedding for query: " . substr($query, 0, 100));
        $queryEmbeddingResult = $this->llmClient->generateEmbedding($query);
        
        if (!$queryEmbeddingResult['success']) {
            $errorMsg = "Error generating embedding for query: " . 
                ($queryEmbeddingResult['error'] ?? 'Unknown error');
            
            if (isset($queryEmbeddingResult['details'])) {
                $errorMsg .= " - Details: " . $queryEmbeddingResult['details'];
            }
            
            if (isset($queryEmbeddingResult['endpoint'])) {
                $errorMsg .= " - Endpoint: " . $queryEmbeddingResult['endpoint'];
            }
            
            error_log($errorMsg);
            
            return [
                'success' => false,
                'error' => $queryEmbeddingResult['error'] ?? 'Unknown error generating query embedding',
                'details' => $queryEmbeddingResult['details'] ?? null,
                'endpoint' => $queryEmbeddingResult['endpoint'] ?? null
            ];
        }
        
        // Search for similar embeddings
        error_log("DEBUG - Searching for similar embeddings");
        $similarEmbeddings = $this->embeddingRepository->searchSimilarEmbeddings(
            $queryEmbeddingResult['embedding'],
            $this->topK
        );
        
        error_log("DEBUG - Found " . count($similarEmbeddings) . " similar embeddings");
        
        if (empty($similarEmbeddings)) {
            return [
                'success' => true,
                'matches' => [],
                'content' => ""
            ];
        }
        
        // Extract content from the matches
        $contents = array_map(function($match) {
            return $match['content'];
        }, $similarEmbeddings);
        
        // Combine content into a single string
        $combinedContent = implode("\n\n", $contents);
        
        return [
            'success' => true,
            'matches' => $similarEmbeddings,
            'content' => $combinedContent
        ];
    }
    
    public function augmentPrompt(string $systemPrompt, string $query, array $retrievalResult = null, array $options = []): string {
        // Use the provided retrievalResult if available, otherwise retrieve content
        if ($retrievalResult === null) {
            $retrievalResult = $this->retrieveRelevantContent($query);
        }
        
        if (!$retrievalResult['success'] || empty($retrievalResult['matches'])) {
            return $systemPrompt;
        }
        
        $contextHeader = $options['context_header'] ?? "### Relevant Context:";
        $contextFooter = $options['context_footer'] ?? "### End Context\n\nPlease use the above context to help answer the user's query:";
        
        $augmentedPrompt = $systemPrompt . "\n\n" . $contextHeader . "\n\n" . $retrievalResult['content'] . "\n\n" . $contextFooter;
        
        return $augmentedPrompt;
    }
} 