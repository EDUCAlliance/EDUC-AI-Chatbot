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

// Determine the root directory assuming admin is one level down
$rootDir = dirname(__DIR__);

// Load the .env file from the root directory
loadEnv($rootDir . '/.env');

// Include Composer's autoloader
require_once $rootDir . '/vendor/autoload.php';

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