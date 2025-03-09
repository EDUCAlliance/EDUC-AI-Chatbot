<?php
namespace EDUC\RAG;

use EDUC\Core\Config;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\EmbeddingRepository;

class DataProcessor {
    private Config $config;
    private LLMClient $llmClient;
    private Database $db;
    private EmbeddingRepository $embeddingRepository;
    private DocumentProcessor $documentProcessor;
    private string $dataDir;
    private int $chunkSize;
    private int $chunkOverlap;
    private int $batchSize;
    
    public function __construct(
        Config $config,
        LLMClient $llmClient,
        Database $db,
        string $dataDir = 'data',
        int $chunkSize = 500,
        int $chunkOverlap = 100,
        int $batchSize = 10
    ) {
        $this->config = $config;
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->embeddingRepository = new EmbeddingRepository($db);
        $this->dataDir = $dataDir;
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
        $this->batchSize = $batchSize;
        
        $this->documentProcessor = new DocumentProcessor(
            $llmClient, 
            $this->embeddingRepository,
            $chunkSize,
            $chunkOverlap,
            $batchSize
        );
    }
    
    public function processAllFiles(): array {
        $results = [];
        
        // Get all files in the data directory
        $files = glob($this->dataDir . '/*.*');
        $totalFiles = count($files);
        
        echo "Found $totalFiles files to process\n";
        
        foreach ($files as $fileIndex => $filePath) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
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
                // Process the file based on its extension
                switch (strtolower($extension)) {
                    case 'json':
                        $results[$filePath] = $this->processJsonFile($filePath);
                        break;
                    case 'txt':
                        $results[$filePath] = $this->processTextFile($filePath);
                        break;
                    case 'md':
                        $results[$filePath] = $this->processMarkdownFile($filePath);
                        break;
                    case 'csv':
                        $results[$filePath] = $this->processCsvFile($filePath);
                        break;
                    default:
                        $results[$filePath] = [
                            'success' => false,
                            'error' => "Unsupported file type: $extension"
                        ];
                        echo "  Skipping unsupported file type: $extension\n";
                }
                
                // Pause briefly between files to allow system to recover resources
                if ($fileSizeMB > 5) {
                    echo "  Pausing briefly to free system resources...\n";
                    gc_collect_cycles(); // Force garbage collection
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
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Could not read text file: $filePath");
        }
        
        $documentId = basename($filePath);
        $result = $this->documentProcessor->processDocument($documentId, 'text', $content);
        
        return [
            'success' => true,
            'results' => [$result]
        ];
    }
    
    public function processMarkdownFile(string $filePath): array {
        // Process markdown files as text for now
        return $this->processTextFile($filePath);
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
} 