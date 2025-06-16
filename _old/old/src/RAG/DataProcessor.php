<?php
namespace EDUC\RAG;

use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\EmbeddingRepository;

class DataProcessor {
    private LLMClient $llmClient;
    private Database $db;
    private EmbeddingRepository $embeddingRepository;
    private DocumentProcessor $documentProcessor;
    private string $dataDir;
    
    public function __construct(
        LLMClient $llmClient,
        Database $db,
        string $dataDir,
        int $chunkSize = 500,
        int $chunkOverlap = 100,
        int $batchSize = 10,
        int $rateLimit = 30
    ) {
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->dataDir = rtrim($dataDir, '/');
        
        // Initialize embedding repository
        $this->embeddingRepository = new EmbeddingRepository($db);
        
        // Initialize document processor
        $this->documentProcessor = new DocumentProcessor(
            $llmClient,
            $this->embeddingRepository,
            $chunkSize,
            $chunkOverlap,
            $batchSize,
            $rateLimit
        );
    }
    
    public function processAllFiles(): array {
        $results = [];
        
        // Get all files in the data directory
        $files = glob($this->dataDir . '/*.*');
        $totalFiles = count($files);
        
        echo "Found $totalFiles files to process\n";
        
        foreach ($files as $fileIndex => $filePath) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $filename = basename($filePath);
            
            echo "\n[" . ($fileIndex + 1) . "/$totalFiles] Processing $filename...\n";
            
            // Get file size and warn if it's large
            $fileSize = filesize($filePath);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            
            if ($fileSizeMB > 1) {
                echo "  Large file detected: $fileSizeMB MB. This may take some time...\n";
                
                // For very large files, give a more specific warning
                if ($fileSizeMB > 10) {
                    echo "  Warning: Very large file ($fileSizeMB MB). Consider breaking it into smaller files if processing fails.\n";
                }
            }
            
            try {
                // Check if file is actually a text file despite its extension
                if ($extension === 'json') {
                    // Try to validate it's actually JSON
                    $isJson = $this->isValidJson($filePath);
                    if (!$isJson) {
                        echo "  File appears to be incorrectly labeled as JSON. Processing as text instead.\n";
                        $results[$filePath] = $this->processTextFile($filePath);
                        continue;
                    }
                }
                
                // Process the file based on its extension
                switch ($extension) {
                    case 'json':
                        $results[$filePath] = $this->processJsonFile($filePath);
                        break;
                    case 'txt':
                    case 'text':
                        $results[$filePath] = $this->processTextFile($filePath);
                        break;
                    case 'md':
                    case 'markdown':
                        $results[$filePath] = $this->processMarkdownFile($filePath);
                        break;
                    case 'csv':
                        $results[$filePath] = $this->processCsvFile($filePath);
                        break;
                    default:
                        // Try to process as text for unknown extensions
                        echo "  Unrecognized file type: $extension. Attempting to process as text...\n";
                        $results[$filePath] = $this->processTextFile($filePath);
                }
                
                // Force garbage collection between files to free memory
                gc_collect_cycles();
                
                // Pause briefly between files to allow system to recover resources
                if ($fileSizeMB > 5) {
                    echo "  Pausing briefly to free system resources...\n";
                    sleep(1); // Pause for 1 second
                }
                
            } catch (\Exception $e) {
                $results[$filePath] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                echo "  Error processing file: " . $e->getMessage() . "\n";
            }
        }
        
        return $results;
    }
    
    public function processJsonFile(string $filePath): array {
        return [
            'success' => true,
            'results' => $this->documentProcessor->processJsonDocument($filePath)
        ];
    }
    
    public function processTextFile(string $filePath): array {
        try {
            return [
                'success' => true,
                'results' => $this->documentProcessor->processTextDocument($filePath, 'text')
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processMarkdownFile(string $filePath): array {
        try {
            return [
                'success' => true,
                'results' => $this->documentProcessor->processTextDocument($filePath, 'markdown')
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processCsvFile(string $filePath): array {
        $file = fopen($filePath, 'r');
        if ($file === false) {
            throw new \Exception("Could not open CSV file: $filePath");
        }
        
        $header = fgetcsv($file);
        if ($header === false) {
            throw new \Exception("Empty CSV file: $filePath");
        }
        
        $results = [];
        $rowIndex = 0;
        
        while (($row = fgetcsv($file)) !== false) {
            $item = [];
            foreach ($header as $index => $key) {
                $item[$key] = $row[$index] ?? '';
            }
            
            $documentId = basename($filePath) . '_' . $rowIndex;
            $content = $this->arrayToText($item);
            
            $results[] = $this->documentProcessor->processDocument($documentId, 'csv', $content, $item);
            
            $rowIndex++;
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'results' => $results
        ];
    }
    
    private function arrayToText(array $array): string {
        $text = '';
        
        foreach ($array as $key => $value) {
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
     * Check if a file contains valid JSON
     *
     * @param string $filePath Path to the file to check
     * @return bool True if the file contains valid JSON
     */
    private function isValidJson(string $filePath): bool {
        // For large files, just check the first few KB
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }
        
        // Read first 8KB to check structure
        $sample = fread($handle, 8192);
        fclose($handle);
        
        // Trim whitespace
        $sample = trim($sample);
        
        // Check if it starts with a JSON character
        if (empty($sample) || ($sample[0] !== '{' && $sample[0] !== '[')) {
            return false;
        }
        
        // Try to decode a small sample
        json_decode($sample);
        return json_last_error() === JSON_ERROR_NONE;
    }
} 