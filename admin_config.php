<?php

// Simple .env loader
function loadEnv($path)
{
    if (!file_exists($path)) {
        throw new Exception(".env file not found at {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Define the application root directory (one level up from public where this file is)
$appRoot = dirname(__DIR__); // Goes from /app/code/public up to /app/code
$rootDir = $appRoot . '/public'; // Keep $rootDir as public if other paths rely on it, but less ideal

// Load the .env file from the *application* root directory
loadEnv('/app/code/.env');

// Include Composer's autoloader from the *application* root directory's vendor folder
require_once $appRoot . '/vendor/autoload.php';

// Function to get the admin password
function getAdminPassword(): string
{
    $adminPassword = getenv('ADMIN_PASSWORD');
    if (!empty($adminPassword)) {
        return $adminPassword;
    }
    $botToken = getenv('BOT_TOKEN');
    if (!empty($botToken)) {
        return $botToken;
    }
    throw new Exception('Admin password is not set in .env (ADMIN_PASSWORD or BOT_TOKEN).');
}

?> 