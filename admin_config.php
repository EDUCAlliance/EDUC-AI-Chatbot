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

// Define the project root directory (one level up from public where this file is)
$projectRoot = dirname(__DIR__); // This should resolve to /app/code

// Load the .env file from the project root directory
loadEnv($projectRoot . '/.env');

// Include Composer's autoloader from the public/vendor directory
require_once __DIR__ . '/vendor/autoload.php'; // Use __DIR__ which is /app/code/public

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