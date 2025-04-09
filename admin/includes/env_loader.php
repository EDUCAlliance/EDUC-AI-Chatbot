<?php

function loadAdminEnv(): void {
    // Hardcoded path as requested
    $envPath = '/app/code/.env'; 
    
    // Check if already loaded
    if (getenv('ADMIN_ENV_LOADED')) {
        return;
    }

    if (!file_exists($envPath)) {
        // Log error or throw exception; for simplicity, just log
        error_log("Admin Error: .env file not found at " . $envPath);
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    // Mark as loaded to prevent reloading in the same request
    putenv('ADMIN_ENV_LOADED=true'); 
} 