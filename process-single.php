<?php
/**
 * EDUC AI TalkBot - Single File Processor
 * 
 * This script processes a single document for the RAG system.
 * 
 * Usage:
 *   php process-single.php <file_path>
 */

// Set very high memory limit for processing
ini_set('memory_limit', '4G');

// Direct includes
require_once __DIR__ . '/src/Core/Environment.php';
require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/API/LLMClient.php';
require_once __DIR__ . '/src/Database/Database.php';
require_once __DIR__ . '/src/Database/EmbeddingRepository.php';
require_once __DIR__ . '/src/RAG/DataProcessor.php';
require_once __DIR__ . '/src/RAG/DocumentProcessor.php';

// Namespace imports
use EDUC\Core\Environment;
use EDUC\Core\Config;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\EmbeddingRepository;
use EDUC\RAG\DataProcessor;
use EDUC\RAG\DocumentProcessor;

// Check parameters
if ($argc < 2) {
    echo "Usage: php process-single.php <file_path>\n";
    exit(1);
}

$filePath = $argv[1];

// Check if file exists
if (!file_exists($filePath)) {
    echo "Error: File not found: $filePath\n";
    exit(1);
}

echo "Starting to process file: $filePath\n";

// Helper function for logging
function log_message($message, $isVerbose = false, $isError = false) {
    $output = date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL;
    if ($isError) {
        fwrite(STDERR, $output);
    } else {
        echo $output;
    }
}

// Load environment variables
try {
    Environment::load('.env');
    log_message("Environment loaded successfully");
} catch (\Exception $e) {
    try {
        Environment::load('/app/code/.env');
        log_message("Environment loaded successfully from /app/code/.env");
    } catch (\Exception $e) {
        log_message("Error loading environment: " . $e->getMessage(), false, true);
        exit(1);
    }
}

// Set up components
try {
    $apiKey = Environment::get('AI_API_KEY');
    $apiEndpoint = Environment::get('AI_API_ENDPOINT');
    $configFile = Environment::get('AI_CONFIG_FILE', 'llm_config.json');
    $dbPath = Environment::get('DB_PATH', dirname(__FILE__) . '/database/chatbot.sqlite');
    
    // Very small chunk size and batch size to reduce memory usage
    $chunkSize = 200;
    $chunkOverlap = 50;
    $batchSize = 1;
    
    log_message("Initializing components with database: $dbPath");
    log_message("Using config file: $configFile");
    
    // Load config
    $config = Config::getInstance($configFile);
    
    // Initialize database
    $db = Database::getInstance($dbPath);
    
    // Initialize API client
    $llmClient = new LLMClient($apiKey, $apiEndpoint);
    
    // Initialize embedding repository
    $embeddingRepository = new EmbeddingRepository($db);
    
    // Initialize document processor with very small chunk size
    $documentProcessor = new DocumentProcessor(
        $llmClient, 
        $embeddingRepository, 
        $chunkSize, 
        $chunkOverlap, 
        $batchSize
    );
} catch (\Exception $e) {
    log_message("Error initializing components: " . $e->getMessage(), false, true);
    exit(1);
}

// Process the file
try {
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    $filename = basename($filePath);
    
    log_message("Processing file: $filename");
    
    // Use memory conservative approach
    if (strtolower($extension) === 'json') {
        // Method 1: Try to process the file as JSON using a more conservative approach
        $result = processJsonFileConservatively($filePath, $documentProcessor);
    } else {
        log_message("Unsupported file type: $extension", false, true);
        exit(1);
    }
    
    log_message("Finished processing file: $filename");
    log_message("Result: " . ($result ? "Success" : "Failure"));
    
} catch (\Exception $e) {
    log_message("Fatal error during processing: " . $e->getMessage(), false, true);
    exit(1);
}

/**
 * Process a JSON file in a very memory-conservative way
 * 
 * @param string $filePath Path to JSON file
 * @param DocumentProcessor $processor Document processor
 * @return bool Success/failure
 */
function processJsonFileConservatively($filePath, $processor) {
    log_message("Processing JSON file conservatively: $filePath");
    
    try {
        // Get the file content
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Could not read file: $filePath");
        }
        
        // Decode the JSON
        $data = json_decode($content, true);
        if ($data === null) {
            throw new Exception("Invalid JSON in file: $filePath");
        }
        
        // Process each item individually
        $itemsProcessed = 0;
        foreach ($data as $index => $item) {
            $documentId = basename($filePath) . '_' . $index;
            
            // Convert item to simple text
            $text = '';
            foreach ($item as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } else if (is_object($value)) {
                    $value = (string)$value;
                }
                
                // Truncate long values
                if (is_string($value) && strlen($value) > 500) {
                    $value = substr($value, 0, 500) . '...';
                }
                
                $text .= $key . ': ' . $value . "\n";
            }
            
            // Process document directly
            $processor->processDocument($documentId, 'json', $text, $item);
            
            $itemsProcessed++;
            
            // Free memory
            unset($text);
            unset($item);
            
            // Force garbage collection every few items
            if ($index % 5 === 0) {
                gc_collect_cycles();
                log_message("Processed $itemsProcessed items...");
            }
        }
        
        log_message("Completed processing $itemsProcessed items from $filePath");
        return true;
        
    } catch (Exception $e) {
        log_message("Error processing JSON file: " . $e->getMessage(), false, true);
        return false;
    }
} 