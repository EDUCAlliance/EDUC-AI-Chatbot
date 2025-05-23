#!/usr/bin/env php
<?php
/**
 * EDUC AI TalkBot - RAG Data Ingestion Script
 * 
 * This script processes documents for the Retrieval Augmented Generation (RAG) system.
 * It can be run from the command line or as a cron job.
 * 
 * Usage:
 *   php ingest-data.php [options]
 * 
 * Options:
 *   --data-dir=PATH   Specify custom data directory (default: ./data)
 *   --force           Force reprocess all documents
 *   --verbose         Show detailed output
 *   --rate-limit=N    Maximum API requests per minute (default: 30)
 *   --help            Display this help message
 */

// Set memory limit for processing large files - more conservative limit
ini_set('memory_limit', '1G');

// Enable better garbage collection
gc_enable();

// Direct includes instead of using Composer autoloader
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

// Parse command line arguments
$options = getopt('', ['data-dir::', 'force', 'verbose', 'rate-limit::', 'help']);

if (isset($options['help'])) {
    echo "EDUC AI TalkBot - RAG Data Ingestion Script\n\n";
    echo "Usage:\n";
    echo "  php ingest-data.php [options]\n\n";
    echo "Options:\n";
    echo "  --data-dir=PATH   Specify custom data directory (default: ./data)\n";
    echo "  --force           Force reprocess all documents\n";
    echo "  --verbose         Show detailed output\n";
    echo "  --rate-limit=N    Maximum API requests per minute (default: 30)\n";
    echo "  --help            Display this help message\n";
    exit(0);
}

$dataDir = $options['data-dir'] ?? __DIR__ . '/data';
$forceReprocess = isset($options['force']);
$verbose = isset($options['verbose']);
$rateLimit = isset($options['rate-limit']) ? (int)$options['rate-limit'] : 30;

// Helper function for logging
function log_message($message, $isVerbose = false, $isError = false) {
    global $verbose;
    
    if ($isError || !$isVerbose || ($isVerbose && $verbose)) {
        $output = date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL;
        if ($isError) {
            fwrite(STDERR, $output);
        } else {
            echo $output;
        }
    }
}

// Load environment variables
try {
    Environment::load('.env');
    log_message("Environment loaded successfully", true);
} catch (\Exception $e) {
    try {
        Environment::load('/app/code/.env');
        log_message("Environment loaded successfully from /app/code/.env", true);
    } catch (\Exception $e) {
        log_message("Error loading environment: " . $e->getMessage(), false, true);
        exit(1);
    }
}

// Set up components
try {
    $apiKey = Environment::get('AI_API_KEY');
    $apiEndpoint = Environment::get('AI_API_ENDPOINT');
    $embeddingEndpoint = Environment::get('EMBEDDING_API_ENDPOINT', '');
    $configFile = Environment::get('AI_CONFIG_FILE', 'llm_config.json');
    $dbPath = Environment::get('DB_PATH', dirname(__FILE__) . '/database/chatbot.sqlite');
    $chunkSize = (int)Environment::get('RAG_CHUNK_SIZE', 500);
    $chunkOverlap = (int)Environment::get('RAG_CHUNK_OVERLAP', 100);
    $batchSize = (int)Environment::get('RAG_BATCH_SIZE', 10);
    
    log_message("Initializing components with database: $dbPath", true);
    log_message("Using config file: $configFile", true);
    log_message("Using data directory: $dataDir", true);
    
    // Initialize database
    $db = Database::getInstance($dbPath);
    
    // Initialize API client
    $llmClient = new LLMClient($apiKey, $apiEndpoint, $embeddingEndpoint);
    
    // Initialize embedding repository
    $embeddingRepository = new EmbeddingRepository($db);
    
    // Initialize document processor
    $documentProcessor = new DocumentProcessor(
        $llmClient, 
        $embeddingRepository, 
        $chunkSize, 
        $chunkOverlap, 
        $batchSize,
        $rateLimit
    );
    
    // Initialize data processor
    $dataProcessor = new DataProcessor(
        $llmClient,
        $db,
        $dataDir,
        $chunkSize,
        $chunkOverlap,
        $batchSize,
        $rateLimit
    );
    
} catch (\Exception $e) {
    log_message("Error initializing components: " . $e->getMessage(), false, true);
    exit(1);
}

// Check if data directory exists
if (!is_dir($dataDir)) {
    log_message("Data directory not found: $dataDir", false, true);
    log_message("Please create the directory and add your documents.", false, true);
    exit(1);
}

log_message("Starting document ingestion from: $dataDir");

// Process all files in the data directory
try {
    if ($forceReprocess) {
        log_message("Force processing all files regardless of previous state");
    }
    
    // Use the DataProcessor to process all files in the data directory
    $results = $dataProcessor->processAllFiles();
    
    // Count results
    $processedCount = 0;
    $errorCount = 0;
    
    foreach ($results as $filePath => $result) {
        if ($result['success']) {
            $processedCount++;
        } else {
            $errorCount++;
            log_message("Error processing $filePath: " . ($result['error'] ?? 'Unknown error'), false, true);
        }
    }
    
    log_message("Ingestion completed:");
    log_message("- Processed: $processedCount files successfully");
    if ($errorCount > 0) {
        log_message("- Errors: $errorCount files failed", false, true);
    }
} catch (\Exception $e) {
    log_message("Fatal error during processing: " . $e->getMessage(), false, true);
    exit(1);
}

exit(0); 