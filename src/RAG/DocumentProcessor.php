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
            // Get the file size
            $fileSize = filesize($filePath);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            error_log("File size: $fileSizeMB MB");
            
            // For extremely small files, we can use a direct approach
            if ($fileSizeMB < 0.1) {
                error_log("Small file detected, using direct approach");
                $jsonContent = file_get_contents($filePath);
                $data = json_decode($jsonContent, true);
                
                if ($data === null) {
                    throw new \Exception("Invalid JSON file: $filePath");
                }
                
                // If it's a single object, convert to array with one item
                if (!is_array($data) || (is_array($data) && !isset($data[0]) && count($data) > 0)) {
                    $data = [$data];
                }
                
                // Process each item
                foreach ($data as $index => $item) {
                    $documentId = basename($filePath) . '_' . $index;
                    
                    // Super simplified content
                    if (count($item) > 20) {
                        $item = array_slice($item, 0, 20, true);
                    }
                    
                    $content = "Item " . ($index + 1) . ":\n";
                    foreach ($item as $key => $value) {
                        if (is_array($value) || is_object($value)) {
                            $value = "[Complex value]";
                        } else if (is_string($value) && strlen($value) > 100) {
                            $value = substr($value, 0, 100) . "...";
                        }
                        $content .= "- $key: $value\n";
                    }
                    
                    try {
                        // Process document with minimal metadata to save memory
                        $result = $this->processDocument(
                            $documentId,
                            $documentType,
                            $content,
                            ['source' => $filePath, 'index' => $index]
                        );
                        $results[] = $result;
                    } catch (\Exception $e) {
                        error_log("Error processing document $documentId: " . $e->getMessage());
                    }
                    
                    // Clear memory
                    unset($item);
                    unset($content);
                }
                
                // Clear memory
                unset($data);
                unset($jsonContent);
                
                // Force garbage collection
                gc_collect_cycles();
                
                error_log("Finished direct processing with " . count($results) . " results");
                
                // Restore original memory limit
                ini_set('memory_limit', $originalMemoryLimit);
                
                return $results;
            }
            
            // For larger files, use the line-by-line streaming approach
            // Ultra-simple JSON streaming for large files
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new \Exception("Could not open JSON file: $filePath");
            }
            
            // For large files (>1MB), use an even more memory-efficient approach
            $isLargeFile = $fileSizeMB > 1;
            
            // Read the first character to verify it's a JSON array
            $firstChar = fread($handle, 1);
            rewind($handle);
            
            if ($firstChar !== '[') {
                fclose($handle);
                throw new \Exception("JSON files must be arrays (starting with '[')");
            }
            
            // For array files, use a simplified line-by-line parsing approach
            // This is much less elegant but much more memory efficient
            
            // Keep track of items processed
            $itemIndex = 0;
            // Use tiny batch size for all files now
            $batchSize = 1; // Process 1 at a time for all files
            $currentBatch = [];
            
            // Simple state tracking
            $currentItem = '';
            $braceCount = 0;
            
            // Keep track of memory
            $initialMemory = memory_get_usage(true);
            $peakMemory = $initialMemory;
            $lastMemoryCheck = time();
            
            // Process line by line
            while (($line = fgets($handle)) !== false) {
                // Monitor memory usage periodically
                if (time() - $lastMemoryCheck > 5) {
                    $currentMemory = memory_get_usage(true);
                    $peakMemory = max($peakMemory, $currentMemory);
                    $memoryDiff = $currentMemory - $initialMemory;
                    
                    error_log(sprintf(
                        "Memory usage: %.2f MB (peak: %.2f MB, diff: %.2f MB)",
                        $currentMemory / 1024 / 1024,
                        $peakMemory / 1024 / 1024,
                        $memoryDiff / 1024 / 1024
                    ));
                    
                    $lastMemoryCheck = time();
                }
                
                // Check if we're out of memory
                $memoryLimit = $this->getMemoryLimitBytes();
                $memoryUsed = memory_get_usage(true);
                
                // If we're within 200MB of the limit, stop processing
                if ($memoryLimit > 0 && ($memoryLimit - $memoryUsed) < 200 * 1024 * 1024) {
                    error_log("Memory limit approaching, stopping processing. Used: " . 
                          round($memoryUsed / (1024 * 1024), 2) . "MB of " . 
                          round($memoryLimit / (1024 * 1024), 2) . "MB");
                    break;
                }
                
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
                    // If we've detected memory pressure, stop processing
                    if ($peakMemory - $initialMemory > 1000 * 1024 * 1024) { // 1GB increase
                        error_log("Memory pressure detected, stopping processing");
                        break;
                    }
                    
                    // Clean up the item - remove trailing commas
                    $currentItem = rtrim($currentItem, ",\r\n ");
                    
                    // Parse the item - use a separate function to contain memory usage
                    $this->processJsonItem($currentItem, $filePath, $documentType, $itemIndex, $results);
                    
                    // Increment item index
                    $itemIndex++;
                    
                    // Log progress periodically
                    if ($itemIndex % 10 === 0) {
                        error_log("Processed $itemIndex JSON items");
                        
                        // Force garbage collection periodically
                        gc_collect_cycles();
                    }
                    
                    // Reset for next item
                    $currentItem = '';
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
     * Process a single JSON item to isolate memory usage
     * 
     * @param string $jsonString JSON string to process
     * @param string $filePath Source file path
     * @param string $documentType Document type
     * @param int $itemIndex Item index
     * @param array &$results Results array to append to
     */
    private function processJsonItem(string $jsonString, string $filePath, string $documentType, int $itemIndex, array &$results): void {
        try {
            // Parse the item
            $item = json_decode($jsonString, true);
            
            if ($item !== null) {
                $documentId = basename($filePath) . '_' . $itemIndex;
                
                // Super simplified content - only use a few fields to save memory
                if (count($item) > 10) {
                    // Extract just a few important fields to reduce size
                    $tempItem = [];
                    $keysToKeep = array_slice(array_keys($item), 0, 10);
                    
                    foreach ($keysToKeep as $key) {
                        if (isset($item[$key])) {
                            $tempItem[$key] = $item[$key];
                        }
                    }
                    
                    $item = $tempItem;
                    unset($tempItem);
                }
                
                // Simplify item to plain text to reduce memory - use directTextConversion
                // which is more efficient than simpleArrayToText
                $content = $this->directTextConversion($item);
                
                // Process one document at a time
                $result = $this->processDocument(
                    $documentId,
                    $documentType,
                    $content,
                    ['source' => $filePath, 'index' => $itemIndex] // Minimal metadata
                );
                
                $results[] = $result;
                
                // Clear memory immediately
                unset($content);
                unset($item);
                
                // Force garbage collection within each item
                gc_collect_cycles();
            }
        } catch (\Exception $e) {
            error_log("Error processing JSON item: " . $e->getMessage());
        }
    }
    
    /**
     * Direct text conversion method - even more memory efficient than simpleArrayToText
     * 
     * @param array $data Array to convert
     * @return string Text representation
     */
    private function directTextConversion(array $data): string {
        $maxKeyLength = 30;
        $maxValueLength = 100;
        $text = '';
        $count = 0;
        
        foreach ($data as $key => $value) {
            $count++;
            
            // Limit to 20 keys max
            if ($count > 20) {
                $text .= "... [Additional fields truncated] ...\n";
                break;
            }
            
            // Truncate long keys
            if (strlen($key) > $maxKeyLength) {
                $key = substr($key, 0, $maxKeyLength) . '...';
            }
            
            // Format the value
            if ($value === null) {
                $formattedValue = 'null';
            } else if (is_bool($value)) {
                $formattedValue = $value ? 'true' : 'false';
            } else if (is_array($value)) {
                $formattedValue = '[Array with ' . count($value) . ' items]';
            } else if (is_object($value)) {
                $formattedValue = '[Object]';
            } else if (is_numeric($value)) {
                $formattedValue = (string)$value;
            } else {
                $formattedValue = (string)$value;
                
                // Truncate long values
                if (strlen($formattedValue) > $maxValueLength) {
                    $formattedValue = substr($formattedValue, 0, $maxValueLength) . '...';
                }
            }
            
            $text .= $key . ': ' . $formattedValue . "\n";
        }
        
        return $text;
    }
    
    /**
     * Split a text segment into chunks (used by splitIntoChunks)
     * 
     * @param string $text Text segment to split
     * @return array Array of chunks
     */
    private function splitSegmentIntoChunks(string $text): array {
        $chunks = [];
        
        // Handle whitespace more efficiently - no regex for large strings
        // Replace consecutive spaces with a single space
        $text = str_replace("\r\n", " ", $text);
        $text = str_replace("\n", " ", $text);
        $text = str_replace("\r", " ", $text);
        $text = str_replace("\t", " ", $text);
        
        // Remove consecutive spaces more efficiently without regex
        while (strpos($text, "  ") !== false) {
            $text = str_replace("  ", " ", $text);
        }
        
        // Simple split by character count
        $textLength = strlen($text);
        $chunkStart = 0;
        
        while ($chunkStart < $textLength) {
            // Check if we have enough memory left
            $memoryUsed = memory_get_usage(true);
            $memoryLimit = $this->getMemoryLimitBytes();
            
            // If we're within 100MB of the limit, stop processing
            if ($memoryLimit > 0 && ($memoryLimit - $memoryUsed) < 100 * 1024 * 1024) {
                error_log("Memory limit approaching, stopping chunk processing. Used: " . 
                          round($memoryUsed / (1024 * 1024), 2) . "MB of " . 
                          round($memoryLimit / (1024 * 1024), 2) . "MB");
                break;
            }
            
            $chunkEnd = $chunkStart + min($this->chunkSize, 200); // Limit max chunk size to 200
            
            // Adjust chunk end to avoid splitting in the middle of a word
            if ($chunkEnd < $textLength) {
                // Look for the first space after the chunk size, but don't look too far
                $nextSpace = strpos($text, ' ', $chunkEnd);
                if ($nextSpace !== false && $nextSpace < $chunkEnd + 20) { // Only look 20 chars ahead
                    $chunkEnd = $nextSpace;
                }
            } else {
                $chunkEnd = $textLength;
            }
            
            // Add the chunk
            $chunk = substr($text, $chunkStart, $chunkEnd - $chunkStart);
            $chunks[] = $chunk;
            
            // Move the start position for the next chunk, accounting for overlap
            $chunkStart = $chunkEnd - min($this->chunkOverlap, 20); // Limit overlap to 20
            
            // Make sure we don't go backward
            if ($chunkStart <= 0) {
                $chunkStart = $chunkEnd;
            }
            
            // Free memory
            unset($chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Get the memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private function getMemoryLimitBytes(): int {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return 0; // Unlimited
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = intval(substr($memoryLimit, 0, -1));
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get the maximum depth of a nested array
     * 
     * @param array $array Array to check
     * @param int $maxDepthToCheck Maximum depth to check to avoid excessive recursion
     * @return int Maximum depth
     */
    private function getArrayDepth(array $array, int $maxDepthToCheck = 3): int {
        // Limited depth check to avoid recursion issues
        if ($maxDepthToCheck <= 0) {
            return 1;
        }
        
        $maxDepth = 1;
        
        // Only check a sample of array elements to save memory
        $sampleSize = min(count($array), 5);
        $sample = array_slice($array, 0, $sampleSize, true);
        
        foreach ($sample as $value) {
            if (is_array($value)) {
                // Reduce maxDepthToCheck for recursion
                $depth = $this->getArrayDepth($value, $maxDepthToCheck - 1) + 1;
                $maxDepth = max($maxDepth, $depth);
                
                // If we've already reached our limit, stop checking
                if ($maxDepth >= $maxDepthToCheck) {
                    return $maxDepth;
                }
            }
        }
        
        return $maxDepth;
    }
    
    /**
     * Simple array to text conversion to minimize memory usage
     * 
     * @param array $array Array to convert
     * @return string Text representation
     */
    private function simpleArrayToText(array $array): string {
        // For extremely large arrays, just return a summary
        if (count($array) > 1000) {
            return "[Large array with " . count($array) . " elements - truncated]";
        }
        
        $text = '';
        $maxValueLength = 200; // Limit value length to 200 chars (reduced from 500)
        $maxKeys = 50; // Limit to 50 keys max (reduced from 100)
        $keyCount = 0;
        
        // For very large arrays, just take the first portion
        if (count($array) > $maxKeys) {
            $array = array_slice($array, 0, $maxKeys, true);
            $text .= "[Array truncated. Only showing first $maxKeys keys]\n";
        }
        
        foreach ($array as $key => $value) {
            $keyCount++;
            
            // Skip if we've hit our key limit
            if ($keyCount > $maxKeys) {
                break;
            }
            
            // Skip null values to save space
            if ($value === null) {
                continue;
            }
            
            // Handle different value types
            if (is_array($value)) {
                // Just count elements
                $count = count($value);
                $value = "[Array with $count elements]";
            } else if (is_object($value)) {
                // For objects, just note the type
                $value = "[Object]";
            } else if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            
            // Truncate long values to save memory
            if (is_string($value) && strlen($value) > $maxValueLength) {
                $value = substr($value, 0, $maxValueLength) . '...';
            }
            
            // Add the key-value pair to the text
            $text .= $key . ': ' . $value . "\n";
        }
        
        return $text;
    }
    
    private function splitIntoChunks(string $text): array {
        $chunks = [];
        
        // For extremely large texts, process in segments to avoid memory issues
        $textLength = strlen($text);
        
        // If text is extremely large, process it in segments
        if ($textLength > 500000) { // 500KB threshold
            // Process text in 250KB segments with overlap
            $segmentSize = 250000;
            $segmentOverlap = 5000;
            $segmentStart = 0;
            
            while ($segmentStart < $textLength) {
                $segmentEnd = min($segmentStart + $segmentSize, $textLength);
                
                // Get this segment
                $segment = substr($text, $segmentStart, $segmentEnd - $segmentStart);
                
                // Process this segment
                $segmentChunks = $this->splitSegmentIntoChunks($segment);
                $chunks = array_merge($chunks, $segmentChunks);
                
                // Move to next segment with overlap
                $segmentStart = $segmentEnd - $segmentOverlap;
                
                // Force garbage collection after each segment
                unset($segment);
                unset($segmentChunks);
                gc_collect_cycles();
            }
        } else {
            // For smaller texts, use the original method
            $chunks = $this->splitSegmentIntoChunks($text);
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