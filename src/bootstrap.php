<?php

declare(strict_types=1);

namespace NextcloudBot;

use PDO;
use PDOException;
use Dotenv\Dotenv;

// Define the application root directory
define('APP_ROOT', dirname(__DIR__));

// --- Autoloading ---
// Load dependencies managed by Composer
require_once APP_ROOT . '/vendor/autoload.php';

// --- Environment Variables ---
// The deployment system provides `auto-include.php`, but for local development,
// we can use .env files. We check for the vendor-managed loader first.
if (file_exists(APP_ROOT . '/.env')) {
    $dotenv = Dotenv::createImmutable(APP_ROOT);
    $dotenv->load();
}

/**
 * Establishes and returns a database connection (PDO object).
 *
 * It uses the environment variables automatically provided by the Cloudron
 * deployment environment. It includes robust error handling and sets
 * default attributes for the connection.
 *
 * @return PDO The configured PDO database handle.
 * @throws PDOException If the database connection fails.
 */
function getDbConnection(): PDO
{
    static $db = null;

    if ($db === null) {
        try {
            // Use standard database environment variables
            $host = getenv('DB_HOST') ?: getenv('CLOUDRON_POSTGRESQL_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: getenv('CLOUDRON_POSTGRESQL_PORT') ?: 5432;
            $database = getenv('DB_NAME') ?: getenv('CLOUDRON_POSTGRESQL_DATABASE') ?: 'educ_ai_talkbot';
            $username = getenv('DB_USER') ?: getenv('CLOUDRON_POSTGRESQL_USERNAME') ?: 'postgres';
            $password = getenv('DB_PASS') ?: getenv('CLOUDRON_POSTGRESQL_PASSWORD') ?: 'password';
            
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            
            $db = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // In a real application, you would log this error instead of dying
            error_log('Database connection failed: ' . $e->getMessage());
            // For security, don't echo connection details
            http_response_code(500);
            die('Internal Server Error: Could not connect to the database.');
        }
    }
    
    return $db;
}

/**
 * A simple helper to safely get an environment variable.
 *
 * @param string $key The environment variable key.
 * @param mixed $default The default value to return if the key is not found.
 * @return mixed The value of the environment variable or the default.
 */
function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value !== false ? $value : $default;
} 