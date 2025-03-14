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
    private int $rateLimit;
    
    public function __construct(
        LLMClient $llmClient, 
        EmbeddingRepository $embeddingRepository,
        int $chunkSize = 500,
        int $chunkOverlap = 100,
        int $batchSize = 10,
        int $rateLimit = 30
    ) {
        $this->llmClient = $llmClient;
        $this->embeddingRepository = $embeddingRepository;
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
        $this->batchSize = $batchSize;
        $this->rateLimit = $rateLimit;
    }
    
    public function processDocument(string $documentId, string $documentType, string $content, array $metadata = []): array {
        // Safety check - limit content size to absolute maximum
        $maxSafeContentSize = 50000; // 50KB absolute maximum
        if (strlen($content) > $maxSafeContentSize) {
            error_log("Warning: Content for document $documentId is very large (" . strlen($content) . " bytes). Truncating to $maxSafeContentSize bytes.");
            $content = substr($content, 0, $maxSafeContentSize) . "\n[Content truncated due to size]";
        }
        
        // Check memory usage before proceeding
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();
        
        // If we're within 500MB of the limit, stop processing
        if ($memoryLimit > 0 && ($memoryLimit - $memoryUsed) < 500 * 1024 * 1024) {
            error_log("Memory limit approaching in processDocument. Used: " . 
                  round($memoryUsed / (1024 * 1024), 2) . "MB of " . 
                  round($memoryLimit / (1024 * 1024), 2) . "MB");
            // Return early with error status
            return [
                'document_id' => $documentId,
                'error' => 'Insufficient memory to process document'
            ];
        }
        
        // Limit metadata size
        if (count($metadata) > 10) {
            error_log("Limiting metadata for document $documentId (had " . count($metadata) . " fields)");
            // Keep only a few essential fields
            $keysToKeep = array_slice(array_keys($metadata), 0, 10);
            $newMetadata = [];
            foreach ($keysToKeep as $key) {
                $newMetadata[$key] = $metadata[$key];
            }
            $metadata = $newMetadata;
        }
        
        $chunks = $this->splitIntoChunks($content);
        $embeddingIds = [];
        
        error_log("Processing document: $documentId with " . count($chunks) . " chunks");
        
        // Store all chunks first
        foreach ($chunks as $index => $chunk) {
            $this->embeddingRepository->storeChunk($documentId, $index, $chunk);
        }
        
        // Process chunks individually instead of in batches to save memory
        // Add throttling to avoid rate limits
        $requestCount = 0;
        $startTime = microtime(true);
        $callsPerMinute = $this->rateLimit; // Target max requests per minute (adjust based on API limits)
        $minTimeBetweenCallsMs = 200; // Minimum 200ms between calls
        $lastCallTime = 0;
        
        foreach ($chunks as $chunkIndex => $chunk) {
            // Check memory before processing each chunk
            $memoryUsed = memory_get_usage(true);
            $memoryLimit = $this->getMemoryLimitBytes();
            
            // If we're within 200MB of the limit, stop processing
            if ($memoryLimit > 0 && ($memoryLimit - $memoryUsed) < 200 * 1024 * 1024) {
                error_log("Memory limit approaching in chunk processing. Stopping early. Used: " . 
                      round($memoryUsed / (1024 * 1024), 2) . "MB of " . 
                      round($memoryLimit / (1024 * 1024), 2) . "MB");
                break;
            }
            
            error_log("Processing chunk $chunkIndex of " . count($chunks));
            
            // Throttle API calls
            $currentTime = microtime(true) * 1000; // Current time in ms
            $elapsedSinceLastCall = $lastCallTime > 0 ? $currentTime - $lastCallTime : 0;
            
            // Ensure minimum time between calls
            if ($elapsedSinceLastCall < $minTimeBetweenCallsMs) {
                $sleepTime = $minTimeBetweenCallsMs - $elapsedSinceLastCall;
                error_log("Throttling: sleeping for {$sleepTime}ms between calls");
                usleep($sleepTime * 1000);
            }
            
            // Calculate rate and throttle if needed
            $requestCount++;
            $elapsedTime = microtime(true) - $startTime;
            $currentRate = $requestCount / ($elapsedTime / 60);
            
            if ($currentRate > $callsPerMinute && $elapsedTime > 10) { // Only throttle if we've been running for at least 10 seconds
                $sleepNeeded = (($requestCount / $callsPerMinute) * 60 - $elapsedTime);
                if ($sleepNeeded > 0) {
                    error_log("Rate throttling: current rate {$currentRate} calls/min exceeds target {$callsPerMinute}. Sleeping for " . round($sleepNeeded * 1000) . "ms");
                    usleep($sleepNeeded * 1000 * 1000); // Convert to microseconds
                }
            }
            
            // Record the start time of this call
            $lastCallTime = microtime(true) * 1000;
            
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
            unset($chunk);
            
            // Force garbage collection on every chunk to prevent memory buildup
            gc_collect_cycles();
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
    
    /**
     * Safely validate a JSON file to see if it's well-formed
     * 
     * @param string $filePath Path to JSON file
     * @return bool True if file is valid JSON, false otherwise
     */
    private function validateJsonFile(string $filePath): bool {
        try {
            // Open file
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                error_log("Could not open file for validation: $filePath");
                return false;
            }
            
            // Read just the first 1KB to check structure
            $firstPart = fread($handle, 1024);
            fclose($handle);
            
            // Check if it starts with [ or {
            $firstChar = trim($firstPart)[0] ?? '';
            if ($firstChar !== '[' && $firstChar !== '{') {
                error_log("JSON file does not start with [ or {: $filePath");
                return false;
            }
            
            // Test with a tiny portion of the file
            $json = json_decode($firstPart, true);
            if (json_last_error() !== JSON_ERROR_NONE && 
                json_last_error() !== JSON_ERROR_SYNTAX) { // Syntax error is expected for a partial file
                error_log("JSON validation error: " . json_last_error_msg());
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("Error validating JSON file: " . $e->getMessage());
            return false;
        }
    }
    
    public function processJsonDocument(string $filePath, string $documentType = 'json'): array {
        // Check if file exists
        if (!file_exists($filePath)) {
            throw new \Exception("JSON file not found: $filePath");
        }
        
        // First, perform a simple validation check
        if (!$this->validateJsonFile($filePath)) {
            throw new \Exception("File $filePath appears to be invalid JSON");
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
                
                // Even for small files, let's use a streaming approach to be safer
                $handle = fopen($filePath, 'r');
                if ($handle === false) {
                    throw new \Exception("Could not open JSON file: $filePath");
                }
                
                // Read the entire file content, but limit to 150KB just in case
                $maxBytesToRead = 150 * 1024; // 150KB max
                $jsonContent = '';
                $bytesRead = 0;
                
                while (!feof($handle) && $bytesRead < $maxBytesToRead) {
                    $chunk = fread($handle, 8192); // Read in 8KB chunks
                    $bytesRead += strlen($chunk);
                    $jsonContent .= $chunk;
                }
                
                fclose($handle);
                
                // If we hit the limit, warn and truncate
                if ($bytesRead >= $maxBytesToRead) {
                    error_log("Warning: File exceeded 150KB read limit. Data may be truncated.");
                    $jsonContent = substr($jsonContent, 0, $maxBytesToRead);
                    // Ensure we have valid JSON by appending a closing bracket if needed
                    $jsonContent = rtrim($jsonContent) . ']';
                }
                
                // Parse JSON with memory limit checks
                $data = null;
                
                try {
                    $data = json_decode($jsonContent, true, 10); // Limit depth to 10
                    
                    // Check for decoding errors
                    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception("JSON parse error: " . json_last_error_msg());
                    }
                    
                    // Clear the raw content immediately to free memory
                    unset($jsonContent);
                    
                    // Force garbage collection
                    gc_collect_cycles();
                    
                    // If it's a single object, convert to array with one item
                    if (!is_array($data)) {
                        $data = [$data];
                    } else if (is_array($data) && !isset($data[0]) && count($data) > 0) {
                        // This is an object with keys, not an array of objects
                        $data = [$data];
                    }
                    
                    // Limit to maximum of 20 items to process
                    if (count($data) > 20) {
                        error_log("Limiting to first 20 items from data array");
                        $data = array_slice($data, 0, 20);
                    }
                    
                    // Process each item with extreme memory consciousness
                    foreach ($data as $index => $item) {
                        // Check memory usage before processing each item
                        $memoryUsed = memory_get_usage(true);
                        $memoryLimit = $this->getMemoryLimitBytes();
                        
                        // If we're within 400MB of the limit, stop processing
                        if ($memoryLimit > 0 && ($memoryLimit - $memoryUsed) < 400 * 1024 * 1024) {
                            error_log("Memory limit approaching, stopping item processing. Used: " . 
                                  round($memoryUsed / (1024 * 1024), 2) . "MB of " . 
                                  round($memoryLimit / (1024 * 1024), 2) . "MB");
                            break;
                        }
                        
                        $documentId = basename($filePath) . '_' . $index;
                        
                        // Extremely simplified content - no more than 5 keys
                        $content = "Item " . ($index + 1) . ":\n";
                        $keyCount = 0;
                        
                        foreach ($item as $key => $value) {
                            // Limit to 5 keys maximum
                            if ($keyCount >= 5) {
                                break;
                            }
                            
                            // Skip null values
                            if ($value === null) {
                                continue;
                            }
                            
                            // Very basic value conversion
                            if (is_array($value) || is_object($value)) {
                                $value = "[Complex value]";
                            } else if (is_bool($value)) {
                                $value = $value ? "true" : "false";
                            } else if (is_string($value)) {
                                if (strlen($value) > 50) {
                                    $value = substr($value, 0, 50) . "...";
                                }
                            } else {
                                $value = (string)$value;
                            }
                            
                            $content .= "- $key: $value\n";
                            $keyCount++;
                        }
                        
                        try {
                            // Process document with absolutely minimal metadata
                            $result = $this->processDocument(
                                $documentId,
                                $documentType,
                                $content,
                                ['source' => basename($filePath)]
                            );
                            $results[] = $result;
                        } catch (\Exception $e) {
                            error_log("Error processing document $documentId: " . $e->getMessage());
                        }
                        
                        // Aggressively clear memory
                        unset($item);
                        unset($content);
                        
                        // Force garbage collection on each item
                        gc_collect_cycles();
                    }
                    
                    // Clear all data
                    unset($data);
                    
                    // Final garbage collection
                    gc_collect_cycles();
                    
                    error_log("Finished direct processing with " . count($results) . " results");
                    
                    // Restore original memory limit
                    ini_set('memory_limit', $originalMemoryLimit);
                    
                    return $results;
                } catch (\Exception $e) {
                    // Clear all variables to free memory
                    unset($jsonContent);
                    unset($data);
                    
                    // Force garbage collection
                    gc_collect_cycles();
                    
                    error_log("Error in direct processing: " . $e->getMessage());
                    
                    // Fall through to the streaming approach
                    error_log("Falling back to streaming approach after direct processing error");
                }
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
        $text = str_replace(["\r\n", "\n", "\r", "\t"], " ", $text);
        
        // Remove consecutive spaces more efficiently without regex
        while (strpos($text, "  ") !== false) {
            $text = str_replace("  ", " ", $text);
        }
        
        // Simple split by character count
        $textLength = strlen($text);
        $chunkStart = 0;
        
        // Set a reasonable chunk limit to prevent memory issues
        $maxChunks = 1000; // Reasonable limit to prevent infinite loops
        $chunkCount = 0;
        
        while ($chunkStart < $textLength && $chunkCount < $maxChunks) {
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
            
            // Use a reasonable max chunk size (smaller than before)
            $chunkEnd = $chunkStart + min($this->chunkSize, 200);
            
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
            $chunks[] = substr($text, $chunkStart, $chunkEnd - $chunkStart);
            $chunkCount++;
            
            // Move the start position for the next chunk, accounting for overlap
            $chunkStart = $chunkEnd - min($this->chunkOverlap, 20); // Limit overlap to 20
            
            // Make sure we don't go backward
            if ($chunkStart <= 0) {
                $chunkStart = $chunkEnd;
            }
            
            // Force garbage collection every 50 chunks
            if ($chunkCount % 50 === 0) {
                gc_collect_cycles();
            }
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
        if ($textLength > 100000) { // Lower threshold to 100KB
            // Process text in smaller segments with overlap
            $segmentSize = 100000; // Smaller segments
            $segmentOverlap = 1000; // Smaller overlap
            $segmentStart = 0;
            
            while ($segmentStart < $textLength) {
                // Check if we're approaching memory limit
                if ($this->isMemoryApproachingLimit(20)) { // 20MB buffer
                    error_log("Memory limit approaching during chunking, stopping at " . 
                             round($segmentStart / $textLength * 100, 1) . "% complete");
                    break;
                }
                
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
            // For smaller texts, use the segment method directly
            $chunks = $this->splitSegmentIntoChunks($text);
        }
        
        return $chunks;
    }
    
    /**
     * Check if memory usage is approaching the limit
     * 
     * @param int $bufferMB Buffer in MB to keep free
     * @return bool True if memory usage is approaching the limit
     */
    private function isMemoryApproachingLimit(int $bufferMB = 50): bool {
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();
        
        if ($memoryLimit <= 0) { // No limit
            return false;
        }
        
        $bufferBytes = $bufferMB * 1024 * 1024;
        return ($memoryLimit - $memoryUsed) < $bufferBytes;
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
    
    /**
     * Process a text document directly
     * 
     * @param string $filePath Path to the text file
     * @param string $documentType Type of document (txt, md, etc)
     * @return array Results of processing
     * @throws \Exception
     */
    public function processTextDocument(string $filePath, string $documentType = 'text'): array {
        // Check if file exists
        if (!file_exists($filePath)) {
            throw new \Exception("Text file not found: $filePath");
        }
        
        // Set higher memory limit for this operation but less than for JSON
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        
        $results = [];
        error_log("Processing $documentType document: $filePath");
        
        try {
            // Get the file size
            $fileSize = filesize($filePath);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            error_log("File size: $fileSizeMB MB");
            
            // For larger files, use a streaming approach to avoid memory issues
            if ($fileSizeMB > 5) {
                error_log("Large file detected, using streaming approach");
                return $this->processLargeTextFile($filePath, $documentType);
            }
            
            // For smaller files, read the whole content
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \Exception("Could not read file: $filePath");
            }
            
            // Process the document
            $documentId = basename($filePath);
            $results[] = $this->processDocument($documentId, $documentType, $content);
            
            // Clean up
            gc_collect_cycles();
            
            return $results;
        } catch (\Exception $e) {
            error_log("Error processing text document: " . $e->getMessage());
            throw $e;
        } finally {
            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }
    
    /**
     * Process a large text file in chunks to avoid memory issues
     * 
     * @param string $filePath Path to the large text file
     * @param string $documentType Type of document
     * @return array Results of processing
     */
    private function processLargeTextFile(string $filePath, string $documentType): array {
        $results = [];
        $documentId = basename($filePath);
        $chunkIndex = 0;
        $batchSize = 64 * 1024; // Read 64KB at a time
        
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \Exception("Could not open file: $filePath");
        }
        
        try {
            $buffer = '';
            $totalBytes = 0;
            
            // Process the file in manageable chunks
            while (!feof($handle)) {
                $chunk = fread($handle, $batchSize);
                $buffer .= $chunk;
                $totalBytes += strlen($chunk);
                
                // When buffer exceeds chunk size, process it
                if (strlen($buffer) >= $this->chunkSize * 2) {
                    // Find a good break point (end of paragraph or sentence)
                    $breakPoint = strrpos(substr($buffer, 0, $this->chunkSize * 1.5), "\n\n");
                    if ($breakPoint === false) {
                        $breakPoint = strrpos(substr($buffer, 0, $this->chunkSize * 1.5), ". ");
                    }
                    if ($breakPoint === false) {
                        $breakPoint = $this->chunkSize;
                    }
                    
                    // Extract content to process
                    $content = substr($buffer, 0, $breakPoint);
                    $buffer = substr($buffer, $breakPoint);
                    
                    // Process this segment
                    $segmentId = $documentId . '_' . $chunkIndex;
                    $results[] = $this->processDocument($segmentId, $documentType, $content);
                    
                    $chunkIndex++;
                }
                
                // Free up memory
                gc_collect_cycles();
            }
            
            // Process remaining buffer
            if (!empty($buffer)) {
                $segmentId = $documentId . '_' . $chunkIndex;
                $results[] = $this->processDocument($segmentId, $documentType, $buffer);
            }
            
            return $results;
        } finally {
            fclose($handle);
        }
    }
} 