<?php
/**
 * EDUC AI TalkBot - Minimal JSON Processor
 * 
 * This script uses a minimal approach to process JSON files without relying
 * on the full framework, for troubleshooting purposes.
 * 
 * Usage:
 *   php minimal-process.php <input_file>
 */

// Set a reasonable memory limit
ini_set('memory_limit', '1G');

// Check parameters
if ($argc < 2) {
    echo "Usage: php minimal-process.php <input_file>\n";
    exit(1);
}

$inputFile = $argv[1];

// Check if input file exists
if (!file_exists($inputFile)) {
    echo "Error: Input file '$inputFile' not found.\n";
    exit(1);
}

echo "Starting to process file: $inputFile\n";

// Load .env file if available
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo "Loading environment from .env file\n";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!empty($name)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

// Get necessary environment variables
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/database/chatbot.sqlite';
$apiKey = getenv('AI_API_KEY');
$apiEndpoint = getenv('AI_API_ENDPOINT');

if (empty($apiKey) || empty($apiEndpoint)) {
    echo "Error: AI_API_KEY and AI_API_ENDPOINT must be set in .env file\n";
    exit(1);
}

echo "Using database: $dbPath\n";
echo "API endpoint: $apiEndpoint\n";

// Make sure the database directory exists
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0777, true)) {
        echo "Error: Unable to create database directory: $dbDir\n";
        exit(1);
    }
}

// Initialize SQLite database connection
try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if tables exist
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='chunks'");
    $chunksTableExists = $stmt->fetch() !== false;
    
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='embeddings'");
    $embeddingsTableExists = $stmt->fetch() !== false;
    
    // Get column info for embeddings table if it exists
    $embeddingColumns = [];
    if ($embeddingsTableExists) {
        $stmt = $db->query("PRAGMA table_info(embeddings)");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $embeddingColumns[$row['name']] = $row;
        }
    }
    
    // Create tables only if they don't exist
    if (!$chunksTableExists) {
        $db->exec("
            CREATE TABLE chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id TEXT NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "Created chunks table\n";
    }
    
    if (!$embeddingsTableExists) {
        $db->exec("
            CREATE TABLE embeddings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id TEXT NOT NULL,
                document_type TEXT NOT NULL,
                chunk_text TEXT NOT NULL,
                embedding BLOB, 
                metadata TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "Created embeddings table\n";
    }
    
    // Check if we need to adapt to existing schema
    $chunkColumnName = isset($embeddingColumns['content']) ? 'content' : 
                     (isset($embeddingColumns['chunk_text']) ? 'chunk_text' : 
                     (isset($embeddingColumns['chunk']) ? 'chunk' : 'content'));
    
    echo "Database initialized successfully\n";
    echo "Using embedding chunk column: $chunkColumnName\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Process the file
try {
    // Read and decode the JSON file
    $fileContents = file_get_contents($inputFile);
    $data = json_decode($fileContents, true);
    
    if ($data === null) {
        throw new Exception("Invalid JSON file");
    }
    
    $totalItems = count($data);
    echo "Found $totalItems items to process\n";
    
    // Process each item individually
    foreach ($data as $index => $item) {
        $documentId = basename($inputFile) . '_' . $index;
        echo "Processing item $index of $totalItems (ID: $documentId)\n";
        
        try {
            // Convert item to text
            $text = '';
            foreach ($item as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } else if (is_object($value)) {
                    $value = (string)$value;
                }
                
                // Truncate long values
                if (is_string($value) && strlen($value) > 300) {
                    $value = substr($value, 0, 300) . '...';
                }
                
                $text .= $key . ': ' . $value . "\n";
            }
            
            // Split text into smaller chunks
            $chunkSize = 200; // characters
            $chunks = [];
            
            if (strlen($text) <= $chunkSize) {
                $chunks[] = $text;
            } else {
                $parts = str_split($text, $chunkSize);
                foreach ($parts as $part) {
                    $chunks[] = $part;
                }
            }
            
            echo "  Split into " . count($chunks) . " chunks\n";
            
            // Store chunks in database
            foreach ($chunks as $chunkIndex => $chunk) {
                $stmt = $db->prepare("
                    INSERT INTO chunks (document_id, chunk_index, content)
                    VALUES (:document_id, :chunk_index, :content)
                ");
                
                $stmt->bindParam(':document_id', $documentId);
                $stmt->bindParam(':chunk_index', $chunkIndex);
                $stmt->bindParam(':content', $chunk);
                $stmt->execute();
                
                $chunkId = $db->lastInsertId();
                echo "  Stored chunk $chunkIndex (ID: $chunkId)\n";
                
                // Store placeholder for embedding (no actual API call)
                $documentType = 'json';
                $metadata = json_encode($item);
                
                $stmt = $db->prepare("
                    INSERT INTO embeddings (document_id, document_type, $chunkColumnName, metadata)
                    VALUES (:document_id, :document_type, :chunk, :metadata)
                ");
                
                $stmt->bindParam(':document_id', $documentId);
                $stmt->bindParam(':document_type', $documentType);
                $stmt->bindParam(':chunk', $chunk);
                $stmt->bindParam(':metadata', $metadata);
                $stmt->execute();
                
                $embeddingId = $db->lastInsertId();
                echo "  Created embedding placeholder $embeddingId\n";
            }
            
            // Clear memory
            unset($text);
            unset($chunks);
            unset($item);
            
            // Garbage collection
            if ($index % 10 === 0) {
                gc_collect_cycles();
            }
            
        } catch (Exception $e) {
            echo "  Error processing item $index: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Finished processing file: $inputFile\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 