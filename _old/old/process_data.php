<?php
require_once 'vendor/autoload.php';

use EDUC\Core\Environment;
use EDUC\Core\Config;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\RAG\DataProcessor;

// Load environment variables
try {
    Environment::load('.env');
} catch (\Exception $e) {
    try {
        Environment::load('/app/code/.env');
    } catch (\Exception $e) {
        die("Error loading environment: " . $e->getMessage());
    }
}

// Parse command line arguments
$shortopts = "";
$longopts = [
    "path:", 
    "force", 
    "verbose",
    "chunk-size:",
    "chunk-overlap:",
    "batch-size:",
    "help"
];

$options = getopt($shortopts, $longopts);

if (isset($options['help'])) {
    echo "Usage: php process_data.php [options]\n";
    echo "Options:\n";
    echo "  --path=<dir>         Directory containing data files (default: data)\n";
    echo "  --force              Force reprocessing of all files\n";
    echo "  --verbose            Display detailed output during processing\n";
    echo "  --chunk-size=<n>     Size of text chunks in characters (default: 500)\n";
    echo "  --chunk-overlap=<n>  Overlap between chunks in characters (default: 100)\n";
    echo "  --batch-size=<n>     Number of chunks to process in a batch (default: 10)\n";
    echo "  --help               Display this help message\n";
    exit(0);
}

$dataPath = $options['path'] ?? 'data';
$forceReprocess = isset($options['force']);
$verbose = isset($options['verbose']);
$chunkSize = isset($options['chunk-size']) ? (int)$options['chunk-size'] : 500;
$chunkOverlap = isset($options['chunk-overlap']) ? (int)$options['chunk-overlap'] : 100;
$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : 10;

function log_message($message, $verbose) {
    if ($verbose) {
        echo date('[Y-m-d H:i:s]') . " " . $message . PHP_EOL;
    }
}

function progress_bar($done, $total, $width = 50) {
    $percentage = round(($done * 100) / $total);
    $bar = round(($width * $percentage) / 100);
    return sprintf("[%s%s] %s%%\r", 
        str_repeat("=", $bar), 
        str_repeat(" ", $width - $bar), 
        $percentage
    );
}

try {
    // Initialize the configuration
    $configPath = Environment::get('AI_CONFIG_FILE');
    log_message("Loading config from: $configPath", $verbose);
    $config = Config::getInstance($configPath);
    
    // Initialize the database
    $dbPath = Environment::get('DB_PATH', dirname(__FILE__) . '/database/chatbot.sqlite');
    log_message("Using database: $dbPath", $verbose);
    
    // Ensure the database directory exists
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        log_message("Creating database directory: $dbDir", $verbose);
        mkdir($dbDir, 0755, true);
    }
    
    $db = Database::getInstance($dbPath);
    
    // Initialize the LLM client
    $llmClient = new LLMClient(
        Environment::get('AI_API_KEY'),
        Environment::get('AI_API_ENDPOINT'),
        Environment::get('EMBEDDING_API_ENDPOINT')
    );
    
    // Display configuration summary
    echo "Configuration Summary:\n";
    echo "  - Data directory: $dataPath\n";
    echo "  - Chunk size: $chunkSize characters\n";
    echo "  - Chunk overlap: $chunkOverlap characters\n";
    echo "  - Batch size: $batchSize chunks\n";
    echo "  - Database: $dbPath\n";
    echo "  - Embedding model: " . Environment::get('EMBEDDING_MODEL', 'e5-mistral-7b-instruct') . "\n";
    echo "\n";
    
    if (!is_dir($dataPath)) {
        die("Error: Directory '$dataPath' does not exist.\n");
    }
    
    // Get list of files to process
    $files = glob($dataPath . '/*.*');
    if (empty($files)) {
        die("Error: No files found in directory '$dataPath'.\n");
    }
    
    echo "Found " . count($files) . " files to process.\n\n";
    
    // Initialize data processor with custom chunk size
    $dataProcessor = new DataProcessor(
        $config, 
        $llmClient, 
        $db, 
        $dataPath, 
        $chunkSize, 
        $chunkOverlap, 
        $batchSize
    );
    
    log_message("Starting data processing from directory: $dataPath", $verbose);
    $startTime = microtime(true);
    
    // Process all files
    $results = $dataProcessor->processAllFiles();
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    
    log_message("Data processing completed in " . number_format($duration, 2) . " seconds", $verbose);
    
    // Display results
    $successCount = 0;
    $errorCount = 0;
    $totalDocuments = 0;
    
    echo "\nProcessing results:\n";
    
    foreach ($results as $filePath => $result) {
        if ($result['success']) {
            $successCount++;
            $fileDocumentCount = count($result['results']);
            $totalDocuments += $fileDocumentCount;
            log_message("✓ Processed $filePath: $fileDocumentCount documents", $verbose);
            
            if ($verbose) {
                $filesize = filesize($filePath);
                $humanFilesize = human_filesize($filesize);
                echo "   - File size: $humanFilesize\n";
                echo "   - Documents created: $fileDocumentCount\n";
                echo "   - Average size per document: " . human_filesize($filesize / max(1, $fileDocumentCount)) . "\n";
                echo "\n";
            }
        } else {
            $errorCount++;
            echo "✗ Failed to process $filePath: " . $result['error'] . "\n";
        }
    }
    
    echo "\nProcessing summary:\n";
    echo "  - Files processed successfully: $successCount\n";
    echo "  - Files with errors: $errorCount\n";
    echo "  - Total documents processed: $totalDocuments\n";
    echo "  - Total duration: " . number_format($duration, 2) . " seconds\n";
    echo "  - Average processing time per document: " . number_format($duration / max(1, $totalDocuments), 4) . " seconds\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function human_filesize($bytes, $decimals = 2) {
    $size = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $size[$factor];
} 