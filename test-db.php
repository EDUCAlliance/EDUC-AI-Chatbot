<?php
/**
 * Simple Database Connection Test for Cloudron
 * Access this at: /apps/educ-ai/test-db.php?debug_key=educ-debug-2024
 */

// Security check
if (getenv('CLOUDRON_ENVIRONMENT') === 'production' && (!isset($_GET['debug_key']) || $_GET['debug_key'] !== 'educ-debug-2024')) {
    http_response_code(404);
    die('Not found');
}

header('Content-Type: text/plain');
echo "=== Database Connection Test ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n\n";

// 1. Check PHP Extensions
echo "1. PHP Extensions Check:\n";
echo "   - pdo: " . (extension_loaded('pdo') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "   - pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "   - pgsql: " . (extension_loaded('pgsql') ? 'LOADED' : 'NOT LOADED') . "\n\n";

// 2. Check PDO Drivers
echo "2. PDO Drivers:\n";
if (class_exists('PDO')) {
    $drivers = PDO::getAvailableDrivers();
    echo "   Available: " . implode(', ', $drivers) . "\n";
    echo "   PostgreSQL: " . (in_array('pgsql', $drivers) ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";
} else {
    echo "   PDO class not available\n";
}
echo "\n";

// 3. Environment Variables
echo "3. Environment Variables:\n";
$envVars = [
    'CLOUDRON_ENVIRONMENT',
    'CLOUDRON_POSTGRESQL_HOST',
    'CLOUDRON_POSTGRESQL_PORT',
    'CLOUDRON_POSTGRESQL_DATABASE',
    'CLOUDRON_POSTGRESQL_USERNAME',
    'CLOUDRON_POSTGRESQL_PASSWORD',
    'CLOUDRON_POSTGRESQL_URL',
    'DATABASE_URL'
];

foreach ($envVars as $var) {
    $value = getenv($var);
    if (stripos($var, 'PASSWORD') !== false || stripos($var, 'URL') !== false) {
        echo sprintf("   %-30s: %s\n", $var, $value ? '[SET]' : 'NOT SET');
    } else {
        echo sprintf("   %-30s: %s\n", $var, $value ?: 'NOT SET');
    }
}
echo "\n";

// 4. Database Connection Test
echo "4. Database Connection Tests:\n";

if (!extension_loaded('pdo_pgsql')) {
    echo "   SKIPPED: pdo_pgsql extension not loaded\n";
} else {
    // Test 1: Individual Parameters
    echo "   Test 1: Individual Parameters\n";
    $host = getenv('CLOUDRON_POSTGRESQL_HOST');
    $port = getenv('CLOUDRON_POSTGRESQL_PORT') ?: '5432';
    $database = getenv('CLOUDRON_POSTGRESQL_DATABASE');
    $username = getenv('CLOUDRON_POSTGRESQL_USERNAME');
    $password = getenv('CLOUDRON_POSTGRESQL_PASSWORD');
    
    if ($host && $database && $username && $password) {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            echo "     DSN: {$dsn}\n";
            echo "     User: {$username}\n";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10
            ];
            
            $start = microtime(true);
            $pdo = new PDO($dsn, $username, $password, $options);
            $time = round((microtime(true) - $start) * 1000, 2);
            
            $version = $pdo->query('SELECT version()')->fetchColumn();
            echo "     Result: SUCCESS (connected in {$time}ms)\n";
            echo "     Version: " . substr($version, 0, 60) . "...\n";
            
            // Test table creation
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS test_connection (id SERIAL PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
                $pdo->exec("INSERT INTO test_connection DEFAULT VALUES");
                $count = $pdo->query("SELECT COUNT(*) FROM test_connection")->fetchColumn();
                echo "     Test table: SUCCESS (rows: {$count})\n";
                $pdo->exec("DROP TABLE test_connection");
            } catch (Exception $e) {
                echo "     Test table: FAILED - " . $e->getMessage() . "\n";
            }
            
        } catch (PDOException $e) {
            echo "     Result: FAILED - " . $e->getMessage() . "\n";
        }
    } else {
        echo "     Result: SKIPPED (missing parameters)\n";
        echo "     Missing: ";
        $missing = [];
        if (!$host) $missing[] = 'host';
        if (!$database) $missing[] = 'database';
        if (!$username) $missing[] = 'username';
        if (!$password) $missing[] = 'password';
        echo implode(', ', $missing) . "\n";
    }
    
    echo "\n   Test 2: Database URL\n";
    $databaseUrl = getenv('CLOUDRON_POSTGRESQL_URL') ?: getenv('DATABASE_URL');
    if ($databaseUrl) {
        try {
            // Parse URL to show details (without password)
            $parsed = parse_url($databaseUrl);
            $safeUrl = ($parsed['scheme'] ?? 'unknown') . '://' . 
                      ($parsed['user'] ?? 'unknown') . ':***@' . 
                      ($parsed['host'] ?? 'unknown') . ':' . 
                      ($parsed['port'] ?? '5432') . '/' . 
                      (ltrim($parsed['path'] ?? '', '/'));
            echo "     URL: {$safeUrl}\n";
            
            $start = microtime(true);
            $pdo = new PDO($databaseUrl);
            $time = round((microtime(true) - $start) * 1000, 2);
            
            echo "     Result: SUCCESS (connected in {$time}ms)\n";
            
        } catch (PDOException $e) {
            echo "     Result: FAILED - " . $e->getMessage() . "\n";
        }
    } else {
        echo "     Result: SKIPPED (no database URL)\n";
    }
}

echo "\n5. Recommendations:\n";
if (!extension_loaded('pdo_pgsql')) {
    echo "   - Install PHP pdo_pgsql extension\n";
    echo "   - In Cloudron, this should be available by default\n";
}

$host = getenv('CLOUDRON_POSTGRESQL_HOST');
if (!$host) {
    echo "   - Set Cloudron PostgreSQL environment variables\n";
    echo "   - Check Cloudron app configuration\n";
} else {
    echo "   - Environment variables appear to be set correctly\n";
}

echo "\n=== End of Test ===\n";
?> 