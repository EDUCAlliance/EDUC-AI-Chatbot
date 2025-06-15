<?php
/**
 * Auto-include file for Cloudron deployment compatibility
 * This file is automatically created by the Cloudron deployment system
 */

// Check if we're running in Cloudron environment
if (getenv('CLOUDRON_ENVIRONMENT')) {
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
    
    // Ensure we have database connection variables
    if (getenv('CLOUDRON_POSTGRESQL_URL') && !getenv('DATABASE_URL')) {
        putenv('DATABASE_URL=' . getenv('CLOUDRON_POSTGRESQL_URL'));
        $_ENV['DATABASE_URL'] = getenv('CLOUDRON_POSTGRESQL_URL');
    }
    
    // Set default API endpoints if not configured
    if (!getenv('AI_API_ENDPOINT')) {
        putenv('AI_API_ENDPOINT=https://chat.hpc.gwdg.de/v1');
        $_ENV['AI_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1';
    }
    
    if (!getenv('EMBEDDING_API_ENDPOINT')) {
        putenv('EMBEDDING_API_ENDPOINT=https://chat.hpc.gwdg.de/v1');
        $_ENV['EMBEDDING_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1';
    }
    
    if (!getenv('MODELS_API_ENDPOINT')) {
        putenv('MODELS_API_ENDPOINT=https://chat.hpc.gwdg.de/v1');
        $_ENV['MODELS_API_ENDPOINT'] = 'https://chat.hpc.gwdg.de/v1';
    }
    
    // Set default values for various settings
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
}
?> 