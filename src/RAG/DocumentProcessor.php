<?php
namespace EDUC\RAG;

use EDUC\API\LLMClient;
use EDUC\Database\EmbeddingRepository;

class DocumentProcessor {
    private LLMClient $llmClient;
    private EmbeddingRepository $embeddingRepository;
    private int $chunkSize;
    private int $chunkOverlap;
    private int $batchSize;
    
    public function __construct(
        LLMClient $llmClient, 
        EmbeddingRepository $embeddingRepository,
        int $chunkSize = 500,
        int $chunkOverlap = 100,
        int $batchSize = 10
    ) {
        $this->llmClient = $llmClient;
        $this->embeddingRepository = $embeddingRepository;
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
        $this->batchSize = $batchSize;
    }
    
    public function processDocument(string $documentId, string $documentType, string $content, array $metadata = []): array {
        $chunks = $this->splitIntoChunks($content);
        $embeddingIds = [];
        
        error_log("Processing document: $documentId with " . count($chunks) . " chunks");
        
        // Store all chunks first
        foreach ($chunks as $index => $chunk) {
            $this->embeddingRepository->storeChunk($documentId, $index, $chunk);
        }
        
        // Process chunks in batches to generate embeddings
        $batches = array_chunk($chunks, $this->batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            error_log("Processing batch $batchIndex of " . count($batches));
            
            foreach ($batch as $index => $chunk) {
                $chunkIndex = $batchIndex * $this->batchSize + $index;
                
                // Generate embedding for the chunk
                $embeddingResult = $this->llmClient->generateEmbedding($chunk);
                
                if (!$embeddingResult['success']) {
                    error_log("Error generating embedding for chunk $chunkIndex: " . ($embeddingResult['error'] ?? 'Unknown error'));
                    continue;
                }
                
                // Store the embedding
                $chunkMetadata = $metadata;
                $chunkMetadata['chunk_index'] = $chunkIndex;
                $embeddingId = $this->embeddingRepository->storeEmbedding(
                    $documentId,
                    $documentType,
                    $chunk,
                    $embeddingResult['embedding'],
                    $chunkMetadata
                );
                
                $embeddingIds[] = $embeddingId;
                
                error_log("Stored embedding $embeddingId for chunk $chunkIndex of document $documentId");
            }
        }
        
        return [
            'document_id' => $documentId,
            'chunk_count' => count($chunks),
            'embedding_ids' => $embeddingIds
        ];
    }
    
    public function processDocumentBatch(array $documents): array {
        $results = [];
        
        foreach ($documents as $index => $document) {
            $results[] = $this->processDocument(
                $document['id'],
                $document['type'],
                $document['content'],
                $document['metadata'] ?? []
            );
            
            // Log progress periodically
            if ($index > 0 && $index % 10 === 0) {
                error_log("Processed $index of " . count($documents) . " documents");
            }
        }
        
        return $results;
    }
    
    public function processJsonDocument(string $filePath, string $documentType = 'json'): array {
        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            throw new \Exception("Could not read JSON file: $filePath");
        }
        
        $data = json_decode($jsonContent, true);
        if ($data === null) {
            throw new \Exception("Invalid JSON file: $filePath");
        }
        
        $results = [];
        $totalItems = count($data);
        error_log("Processing JSON document with $totalItems items");
        
        // Prepare batch of documents to process
        $documentBatches = [];
        $currentBatch = [];
        $batchSize = 25; // Process 25 JSON items at a time
        
        foreach ($data as $index => $item) {
            $documentId = basename($filePath) . '_' . $index;
            $content = $this->jsonItemToText($item);
            
            $currentBatch[] = [
                'id' => $documentId,
                'type' => $documentType,
                'content' => $content,
                'metadata' => $item
            ];
            
            // When batch is full or we're at the end, process it
            if (count($currentBatch) >= $batchSize || $index === $totalItems - 1) {
                $batchResults = $this->processDocumentBatch($currentBatch);
                $results = array_merge($results, $batchResults);
                $currentBatch = [];
                
                error_log("Processed " . ($index + 1) . " of $totalItems JSON items");
            }
        }
        
        return $results;
    }
    
    private function splitIntoChunks(string $text): array {
        $chunks = [];
        
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Simple split by character count
        $textLength = strlen($text);
        $chunkStart = 0;
        
        while ($chunkStart < $textLength) {
            $chunkEnd = $chunkStart + $this->chunkSize;
            
            // Adjust chunk end to avoid splitting in the middle of a word
            if ($chunkEnd < $textLength) {
                // Look for the first space after the chunk size
                $nextSpace = strpos($text, ' ', $chunkEnd);
                if ($nextSpace !== false) {
                    $chunkEnd = $nextSpace;
                }
            } else {
                $chunkEnd = $textLength;
            }
            
            $chunks[] = substr($text, $chunkStart, $chunkEnd - $chunkStart);
            
            // Move the start position for the next chunk, accounting for overlap
            $chunkStart = $chunkEnd - $this->chunkOverlap;
            
            // Make sure we don't go backward
            if ($chunkStart <= 0) {
                $chunkStart = $chunkEnd;
            }
        }
        
        return $chunks;
    }
    
    private function jsonItemToText(array $item): string {
        $text = '';
        
        foreach ($item as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(function($v) {
                    return is_array($v) ? json_encode($v) : $v;
                }, $value));
            }
            
            $text .= $key . ': ' . $value . "\n";
        }
        
        return $text;
    }
} 