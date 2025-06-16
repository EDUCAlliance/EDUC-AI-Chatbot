<?php
/**
 * EDUC Bootstrap file for Cloudron deployment compatibility
 * This file handles environment setup specific to the EDUC AI TalkBot
 */

// Check if we're running in Cloudron environment
if (getenv('CLOUDRON_ENVIRONMENT') || getenv('CLOUDRON_POSTGRESQL_HOST')) {
    // Load custom environment variables if they exist
    $customEnvFile = __DIR__ . '/custom-env.php';
    if (file_exists($customEnvFile)) {
        require_once $customEnvFile;
    }
    
    // Set some default environment variables if not already set
    if (!getenv('APP_NAME')) {
        putenv('APP_NAME=EDUC-AI-Chatbot');
        $_ENV['APP_NAME'] = 'EDUC-AI-Chatbot';
    }
    
    if (!getenv('APP_DIRECTORY')) {
        putenv('APP_DIRECTORY=educ-ai-talkbot');
        $_ENV['APP_DIRECTORY'] = 'educ-ai-talkbot';
    }
    
    // Map Cloudron database variables to standard format (individual parameters only)
    if (getenv('CLOUDRON_POSTGRESQL_HOST') && !getenv('DB_HOST')) {
        putenv('DB_HOST=' . getenv('CLOUDRON_POSTGRESQL_HOST'));
        $_ENV['DB_HOST'] = getenv('CLOUDRON_POSTGRESQL_HOST');
    }
    
    if (getenv('CLOUDRON_POSTGRESQL_PORT') && !getenv('DB_PORT')) {
        putenv('DB_PORT=' . getenv('CLOUDRON_POSTGRESQL_PORT'));
        $_ENV['DB_PORT'] = getenv('CLOUDRON_POSTGRESQL_PORT');
    }
    
    if (getenv('CLOUDRON_POSTGRESQL_DATABASE') && !getenv('DB_NAME')) {
        putenv('DB_NAME=' . getenv('CLOUDRON_POSTGRESQL_DATABASE'));
        $_ENV['DB_NAME'] = getenv('CLOUDRON_POSTGRESQL_DATABASE');
    }
    
    if (getenv('CLOUDRON_POSTGRESQL_USERNAME') && !getenv('DB_USER')) {
        putenv('DB_USER=' . getenv('CLOUDRON_POSTGRESQL_USERNAME'));
        $_ENV['DB_USER'] = getenv('CLOUDRON_POSTGRESQL_USERNAME');
    }
    
    if (getenv('CLOUDRON_POSTGRESQL_PASSWORD') && !getenv('DB_PASSWORD')) {
        putenv('DB_PASSWORD=' . getenv('CLOUDRON_POSTGRESQL_PASSWORD'));
        $_ENV['DB_PASSWORD'] = getenv('CLOUDRON_POSTGRESQL_PASSWORD');
    }
    
    // Diagnostic logging for database connection
    if (function_exists('error_log')) {
        $logPrefix = '[EDUC-Chatbot INIT]';
        
        // Check if PDO PostgreSQL is available
        if (!extension_loaded('pdo_pgsql')) {
            error_log("{$logPrefix} WARNING: pdo_pgsql extension not loaded");
        }
        
        // Check available PDO drivers
        if (class_exists('PDO')) {
            $drivers = PDO::getAvailableDrivers();
            if (!in_array('pgsql', $drivers)) {
                error_log("{$logPrefix} ERROR: PostgreSQL driver not available. Available: " . implode(', ', $drivers));
            }
        }
        
        // Check database environment variables (individual parameters)
        $dbVars = [
            'CLOUDRON_POSTGRESQL_HOST',
            'CLOUDRON_POSTGRESQL_DATABASE', 
            'CLOUDRON_POSTGRESQL_USERNAME'
        ];
        
        $missingVars = [];
        foreach ($dbVars as $var) {
            if (!getenv($var)) {
                $missingVars[] = $var;
            }
        }
        
        if (!empty($missingVars)) {
            error_log("{$logPrefix} WARNING: Missing database environment variables: " . implode(', ', $missingVars));
        } else {
            error_log("{$logPrefix} INFO: All required database parameters present");
        }
    }
    
    // Set default API endpoints if not configured
    if (!getenv('AI_API_ENDPOINT')) {
        putenv('AI_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/chat/completions');
        $_ENV['AI_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1/chat/completions';
    }
    
    if (!getenv('EMBEDDING_API_ENDPOINT')) {
        putenv('EMBEDDING_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/embeddings');
        $_ENV['EMBEDDING_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1/embeddings';
    }
    
    if (!getenv('MODELS_API_ENDPOINT')) {
        putenv('MODELS_API_ENDPOINT=https://chat.hpc.gwdg.de/v1/models');
        $_ENV['MODELS_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1/models';
    }
    
    // Set default values for optional settings
    if (!getenv('USE_RAG')) {
        putenv('USE_RAG=true');
        $_ENV['USE_RAG'] = 'true';
    }
    
    if (!getenv('RAG_TOP_K')) {
        putenv('RAG_TOP_K=5');
        $_ENV['RAG_TOP_K'] = '5';
    }
    
    if (!getenv('LOG_LEVEL')) {
        putenv('LOG_LEVEL=INFO');
        $_ENV['LOG_LEVEL'] = 'INFO';
    }
    
    if (!getenv('DEBUG_MODE')) {
        putenv('DEBUG_MODE=false');
        $_ENV['DEBUG_MODE'] = 'false';
    }
}
?> 