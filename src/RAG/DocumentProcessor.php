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
        // Check if content exceeds maximum size
        $maxContentSize = 100000; // 100KB limit for a single document
        if (strlen($content) > $maxContentSize) {
            error_log("Warning: Content for document $documentId is very large (" . strlen($content) . " bytes). Truncating...");
            $content = substr($content, 0, $maxContentSize) . "\n[Content truncated due to size]";
        }
        
        $chunks = $this->splitIntoChunks($content);
        $embeddingIds = [];
        
        error_log("Processing document: $documentId with " . count($chunks) . " chunks");
        
        // Store all chunks first
        foreach ($chunks as $index => $chunk) {
            $this->embeddingRepository->storeChunk($documentId, $index, $chunk);
        }
        
        // Process chunks individually instead of in batches to save memory
        foreach ($chunks as $chunkIndex => $chunk) {
            error_log("Processing chunk $chunkIndex of " . count($chunks));
            
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
            
            // Free memory
            unset($embeddingResult);
            if ($chunkIndex % 5 === 0) {  // Every 5 chunks
                gc_collect_cycles();  // Force garbage collection
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
        // Check if file exists
        if (!file_exists($filePath)) {
            throw new \Exception("JSON file not found: $filePath");
        }
        
        // Set higher memory limit for this operation
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '2G');
        
        $results = [];
        error_log("Processing JSON document: $filePath");
        
        try {
            // Ultra-simple JSON streaming for large files
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new \Exception("Could not open JSON file: $filePath");
            }
            
            // Get the file size
            $fileSize = filesize($filePath);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            error_log("File size: $fileSizeMB MB");
            
            // Read the first character to verify it's a JSON array
            $firstChar = fread($handle, 1);
            rewind($handle);
            
            if ($firstChar !== '[') {
                // For small non-array files, just load the whole thing
                if ($fileSizeMB < 1) {
                    $jsonContent = file_get_contents($filePath);
                    $data = json_decode($jsonContent, true);
                    
                    if ($data === null) {
                        throw new \Exception("Invalid JSON file: $filePath");
                    }
                    
                    // Process as single item
                    $documentId = basename($filePath) . '_0';
                    $content = is_array($data) ? $this->simpleArrayToText($data) : json_encode($data);
                    
                    $result = $this->processDocument(
                        $documentId,
                        $documentType,
                        $content,
                        is_array($data) ? $data : ['content' => $data]
                    );
                    
                    return [$result];
                } else {
                    throw new \Exception("Large JSON files must be arrays (starting with '[')");
                }
            }
            
            // For array files, use a simplified line-by-line parsing approach
            // This is much less elegant but much more memory efficient
            
            // Keep track of items processed
            $itemIndex = 0;
            $batchSize = 5; // Process in very small batches to save memory
            $currentBatch = [];
            
            // Simple state tracking
            $currentItem = '';
            $braceCount = 0;
            $inArray = false;
            
            // Process line by line
            while (($line = fgets($handle)) !== false) {
                // If we're between items or at the start
                if (trim($line) === '[' || trim($line) === ',' || trim($line) === ']') {
                    continue; // Skip array markers
                }
                
                // Start collecting an item
                $currentItem .= $line;
                
                // Count open braces to know when we have a complete item
                $braceOpen = substr_count($line, '{');
                $braceClose = substr_count($line, '}');
                $braceCount += $braceOpen - $braceClose;
                
                // Once we've seen a balanced set of braces, we have a complete item
                if ($braceCount === 0 && !empty($currentItem)) {
                    // Clean up the item - remove trailing commas
                    $currentItem = rtrim($currentItem, ",\r\n ");
                    
                    // Parse the item
                    try {
                        $item = json_decode($currentItem, true);
                        
                        if ($item !== null) {
                            $documentId = basename($filePath) . '_' . $itemIndex;
                            
                            // Simplify item to plain text to reduce memory
                            $content = $this->simpleArrayToText($item);
                            
                            $currentBatch[] = [
                                'id' => $documentId,
                                'type' => $documentType,
                                'content' => $content,
                                'metadata' => $item
                            ];
                            
                            $itemIndex++;
                            
                            // Process a small batch at a time
                            if (count($currentBatch) >= $batchSize) {
                                foreach ($currentBatch as $doc) {
                                    try {
                                        // Process one document at a time instead of batches
                                        $result = $this->processDocument(
                                            $doc['id'],
                                            $doc['type'],
                                            $doc['content'],
                                            $doc['metadata']
                                        );
                                        $results[] = $result;
                                    } catch (\Exception $e) {
                                        error_log("Error processing document: " . $e->getMessage());
                                    }
                                    
                                    // Clear document from memory
                                    unset($doc);
                                }
                                
                                // Clear the batch
                                $currentBatch = [];
                                
                                // Force garbage collection
                                gc_collect_cycles();
                                
                                error_log("Processed $itemIndex JSON items");
                            }
                        }
                    } catch (\Exception $e) {
                        error_log("Error parsing JSON item: " . $e->getMessage());
                    }
                    
                    // Reset for next item
                    $currentItem = '';
                }
            }
            
            // Process any remaining items
            foreach ($currentBatch as $doc) {
                try {
                    $result = $this->processDocument(
                        $doc['id'],
                        $doc['type'],
                        $doc['content'],
                        $doc['metadata']
                    );
                    $results[] = $result;
                } catch (\Exception $e) {
                    error_log("Error processing document: " . $e->getMessage());
                }
            }
            
            // Close the file
            fclose($handle);
            
            error_log("Finished processing $itemIndex JSON items");
            
            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);
            
            return $results;
        } catch (\Exception $e) {
            // Restore original memory limit on error
            ini_set('memory_limit', $originalMemoryLimit);
            
            // Close handle if open
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            
            error_log("Error processing JSON file: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Simple array to text conversion to minimize memory usage
     * 
     * @param array $array Array to convert
     * @return string Text representation
     */
    private function simpleArrayToText(array $array): string {
        $text = '';
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // For nested arrays, just use JSON encoding
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else if (is_object($value)) {
                // For objects, convert to string
                $value = (string)$value;
            }
            
            // Truncate long values to save memory
            if (is_string($value) && strlen($value) > 1000) {
                $value = substr($value, 0, 1000) . '...';
            }
            
            $text .= $key . ': ' . $value . "\n";
        }
        
        return $text;
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