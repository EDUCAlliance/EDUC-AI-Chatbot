<?php
/**
 * Nextcloud AI Chatbot - Application Bootstrap
 * 
 * This file initializes the application environment, loads configuration,
 * and sets up the necessary services and dependencies.
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('PHP_SAPI') || PHP_SAPI === 'cli') {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'bootstrap.php') {
        die('Direct access not allowed');
    }
}

// Error reporting configuration
if (getenv('CLOUDRON_ENVIRONMENT') === 'production') {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Set timezone
date_default_timezone_set('UTC');

// Load environment variables
use Dotenv\Dotenv;
use EducBot\Helpers\Logger;

// Try to load .env file if it exists
if (file_exists(__DIR__ . '/../../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
    $dotenv->load();
}

// Ensure required environment variables
$requiredEnvVars = [
    'BOT_TOKEN' => 'Shared secret for webhook HMAC validation',
    'NC_URL' => 'Nextcloud base domain',
    'AI_API_KEY' => 'SAIA API key',
];

$missingVars = [];
foreach ($requiredEnvVars as $var => $description) {
    if (empty(getenv($var))) {
        $missingVars[] = "$var ($description)";
    }
}

if (!empty($missingVars)) {
    Logger::error('Missing required environment variables', $missingVars);
    throw new \RuntimeException('Missing required environment variables: ' . implode(', ', $missingVars));
}

// Set default values for optional environment variables
$defaultEnvVars = [
    'AI_API_ENDPOINT' => 'https://chat-ai.academiccloud.de/v1',
    'DEFAULT_MODEL' => 'meta-llama-3.1-8b-instruct',
    'EMBEDDING_MODEL' => 'e5-mistral-7b-instruct',
    'RAG_TOP_K' => '5',
    'MAX_TOKENS' => '512',
    'TEMPERATURE' => '0.7',
    'BOT_MENTION' => '@educai',
    'LOG_LEVEL' => 'INFO',
];

foreach ($defaultEnvVars as $var => $default) {
    if (empty(getenv($var))) {
        putenv("$var=$default");
    }
}

// Initialize logger
Logger::initialize();

// Database connection helper
function getDbConnection(): PDO {
    static $db = null;
    
    if ($db === null) {
        try {
            // Try Cloudron environment first
            $host = getenv('CLOUDRON_POSTGRESQL_HOST');
            $port = getenv('CLOUDRON_POSTGRESQL_PORT') ?: '5432';
            $database = getenv('CLOUDRON_POSTGRESQL_DATABASE');
            $username = getenv('CLOUDRON_POSTGRESQL_USERNAME');
            $password = getenv('CLOUDRON_POSTGRESQL_PASSWORD');
            
            if ($host && $database && $username && $password) {
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            } else {
                // Fallback to manual configuration
                $dsn = getenv('DATABASE_URL') ?: 'pgsql:host=localhost;dbname=nextcloud_bot';
                $username = getenv('DB_USERNAME') ?: 'postgres';
                $password = getenv('DB_PASSWORD') ?: '';
            }
            
            $db = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            // Enable pgvector extension if available
            try {
                $db->exec('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (PDOException $e) {
                Logger::warning('Could not enable pgvector extension', ['error' => $e->getMessage()]);
            }
            
        } catch (PDOException $e) {
            Logger::error('Database connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }
    
    return $db;
}

// Initialize database schema
function initializeDatabase(): void {
    try {
        $db = getDbConnection();
        
        // Create tables if they don't exist
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('Could not load database schema');
        }
        
        $db->exec($schema);
        
        Logger::info('Database schema initialized successfully');
        
    } catch (Exception $e) {
        Logger::error('Database initialization failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}

// Auto-initialize database on first load
try {
    initializeDatabase();
} catch (Exception $e) {
    // Log but don't fail - admin panel can handle initialization
    Logger::warning('Auto database initialization failed', ['error' => $e->getMessage()]);
}

// Global exception handler
set_exception_handler(function (Throwable $e) {
    Logger::error('Uncaught exception', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    if (getenv('CLOUDRON_ENVIRONMENT') !== 'production') {
        echo "Uncaught exception: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}); 