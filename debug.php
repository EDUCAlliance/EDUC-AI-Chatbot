<?php
/**
 * EDUC AI TalkBot - Diagnostic Script
 * Use this to debug PostgreSQL connection and environment issues
 */

// Security check - only run in development or with special parameter
if (getenv('CLOUDRON_ENVIRONMENT') === 'production' && !isset($_GET['debug_key']) || $_GET['debug_key'] !== 'educ-debug-2024') {
    http_response_code(404);
    die('Not found');
}

header('Content-Type: text/plain');

echo "=== EDUC AI TalkBot Diagnostic Report ===\n";
echo "Generated: " . date('Y-m-d H:i:s T') . "\n\n";

// PHP Information
echo "=== PHP Information ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "Error Reporting: " . error_reporting() . "\n";
echo "Display Errors: " . ini_get('display_errors') . "\n\n";

// Extensions
echo "=== PHP Extensions ===\n";
$requiredExtensions = ['pdo', 'pdo_pgsql', 'pgsql', 'curl', 'json', 'mbstring', 'fileinfo', 'gd', 'zip', 'openssl'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    echo sprintf("%-12s: %s\n", $ext, $loaded ? 'LOADED' : 'NOT LOADED');
}
echo "\n";

// PDO Drivers
echo "=== PDO Drivers ===\n";
try {
    $drivers = PDO::getAvailableDrivers();
    echo "Available drivers: " . implode(', ', $drivers) . "\n";
    echo "PostgreSQL driver: " . (in_array('pgsql', $drivers) ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";
} catch (Exception $e) {
    echo "Error getting PDO drivers: " . $e->getMessage() . "\n";
}
echo "\n";

// Environment Variables
echo "=== Environment Variables ===\n";

// Cloudron Environment
$cloudronVars = [
    'CLOUDRON_ENVIRONMENT',
    'CLOUDRON_APP_DOMAIN',
    'CLOUDRON_APP_ORIGIN',
    'APP_NAME',
    'APP_ID',
    'APP_DIRECTORY'
];

echo "Cloudron Variables:\n";
foreach ($cloudronVars as $var) {
    $value = getenv($var);
    echo sprintf("  %-25s: %s\n", $var, $value ?: 'NOT SET');
}
echo "\n";

// Database Variables
$dbVars = [
    'CLOUDRON_POSTGRESQL_HOST',
    'CLOUDRON_POSTGRESQL_PORT',
    'CLOUDRON_POSTGRESQL_DATABASE',
    'CLOUDRON_POSTGRESQL_USERNAME',
    'CLOUDRON_POSTGRESQL_PASSWORD',
    'CLOUDRON_POSTGRESQL_URL',
    'DATABASE_URL'
];

echo "Database Variables:\n";
foreach ($dbVars as $var) {
    $value = getenv($var);
    if (stripos($var, 'PASSWORD') !== false || stripos($var, 'URL') !== false) {
        echo sprintf("  %-30s: %s\n", $var, $value ? '[SET - ' . strlen($value) . ' chars]' : 'NOT SET');
    } else {
        echo sprintf("  %-30s: %s\n", $var, $value ?: 'NOT SET');
    }
}
echo "\n";

// Custom App Variables
$customVars = [
    'AI_API_KEY',
    'AI_API_ENDPOINT',
    'EMBEDDING_API_ENDPOINT',
    'MODELS_API_ENDPOINT',
    'BOT_TOKEN',
    'NC_URL',
    'USE_RAG',
    'RAG_TOP_K',
    'LOG_LEVEL',
    'DEBUG_MODE'
];

echo "Application Variables:\n";
foreach ($customVars as $var) {
    $value = getenv($var);
    if (stripos($var, 'KEY') !== false || stripos($var, 'TOKEN') !== false) {
        echo sprintf("  %-20s: %s\n", $var, $value ? '[SET - ' . strlen($value) . ' chars]' : 'NOT SET');
    } else {
        echo sprintf("  %-20s: %s\n", $var, $value ?: 'NOT SET');
    }
}
echo "\n";

// File System
echo "=== File System ===\n";
$paths = [
    'Current directory' => getcwd(),
    'Script directory' => __DIR__,
    'Uploads directory' => __DIR__ . '/uploads',
    'Cache directory' => __DIR__ . '/cache',
    'Logs directory' => __DIR__ . '/logs',
    'Vendor directory' => __DIR__ . '/vendor',
    'Auto-include file' => __DIR__ . '/auto-include.php'
];

foreach ($paths as $label => $path) {
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    $writable = $exists ? is_writable($path) : false;
    
    echo sprintf("%-20s: %s", $label, $path);
    if ($exists) {
        echo " [EXISTS";
        if ($readable) echo ", READABLE";
        if ($writable) echo ", WRITABLE";
        echo "]";
    } else {
        echo " [NOT FOUND]";
    }
    echo "\n";
}
echo "\n";

// Autoloader
echo "=== Composer Autoloader ===\n";
$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    echo "Autoloader file: EXISTS\n";
    try {
        require_once $autoloadFile;
        echo "Autoloader: LOADED SUCCESSFULLY\n";
        
        // Test class loading
        $testClasses = [
            'EDUC\\Core\\Environment',
            'EDUC\\Database\\Database',
            'EDUC\\Utils\\Logger',
            'EDUC\\Utils\\Security'
        ];
        
        echo "Class loading test:\n";
        foreach ($testClasses as $class) {
            echo sprintf("  %-25s: %s\n", $class, class_exists($class) ? 'FOUND' : 'NOT FOUND');
        }
    } catch (Exception $e) {
        echo "Autoloader error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Autoloader file: NOT FOUND\n";
    echo "Run 'composer install' to install dependencies\n";
}
echo "\n";

// Database Connection Test
echo "=== Database Connection Test ===\n";
try {
    if (!extension_loaded('pdo_pgsql')) {
        echo "ERROR: PDO PostgreSQL extension not loaded\n";
    } else {
        // Try different connection methods
        $host = getenv('CLOUDRON_POSTGRESQL_HOST');
        $port = getenv('CLOUDRON_POSTGRESQL_PORT') ?: '5432';
        $database = getenv('CLOUDRON_POSTGRESQL_DATABASE');
        $username = getenv('CLOUDRON_POSTGRESQL_USERNAME');
        $password = getenv('CLOUDRON_POSTGRESQL_PASSWORD');
        
        if ($host && $database && $username && $password) {
            echo "Attempting connection with individual parameters...\n";
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            echo "DSN: {$dsn}\n";
            echo "Username: {$username}\n";
            
            try {
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]);
                
                $version = $pdo->query('SELECT version()')->fetchColumn();
                echo "SUCCESS: Connected to PostgreSQL\n";
                echo "Version: " . substr($version, 0, 80) . "\n";
                
                // Test pgvector
                try {
                    $pdo->exec("CREATE EXTENSION IF NOT EXISTS vector");
                    echo "pgvector: AVAILABLE\n";
                } catch (Exception $e) {
                    echo "pgvector: NOT AVAILABLE (" . $e->getMessage() . ")\n";
                }
                
            } catch (PDOException $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Missing required database parameters\n";
        }
        
        // Try URL connection
        $databaseUrl = getenv('CLOUDRON_POSTGRESQL_URL') ?: getenv('DATABASE_URL');
        if ($databaseUrl) {
            echo "\nAttempting connection with database URL...\n";
            try {
                $pdo = new PDO($databaseUrl);
                echo "SUCCESS: Connected using database URL\n";
            } catch (PDOException $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// Application Test
echo "=== Application Test ===\n";
if (file_exists($autoloadFile)) {
    try {
        require_once $autoloadFile;
        require_once __DIR__ . '/auto-include.php';
        
        // Test Environment class
        if (class_exists('EDUC\\Core\\Environment')) {
            $envClass = 'EDUC\\Core\\Environment';
            $envClass::load();
            echo "Environment class: LOADED\n";
        } else {
            echo "Environment class: NOT FOUND\n";
        }
        
        // Test Logger class
        if (class_exists('EDUC\\Utils\\Logger')) {
            $loggerClass = 'EDUC\\Utils\\Logger';
            $loggerClass::initialize();
            echo "Logger class: LOADED\n";
            
            if (method_exists($envClass, 'getLogsPath')) {
                echo "Log path: " . $envClass::getLogsPath() . "\n";
            }
        } else {
            echo "Logger class: NOT FOUND\n";
        }
        
        // Test database connection through our class
        try {
            if (class_exists('EDUC\\Database\\Database')) {
                $dbClass = 'EDUC\\Database\\Database';
                $db = $dbClass::getInstance();
                echo "Database class: SUCCESS\n";
            } else {
                echo "Database class: NOT FOUND\n";
            }
        } catch (Exception $e) {
            echo "Database class: FAILED - " . $e->getMessage() . "\n";
        }
        
    } catch (Exception $e) {
        echo "Application test failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== End of Diagnostic Report ===\n";
?> 