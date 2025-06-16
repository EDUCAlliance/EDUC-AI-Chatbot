<?php
/**
 * Admin Panel Shared Functions
 */

require_once __DIR__ . '/auth.php';

/**
 * Get system statistics
 */
function getSystemStats(): array {
    $db = \EDUC\Database\Database::getInstance();
    $prefix = $db->getTablePrefix();
    
    $stats = [];
    
    try {
        // Message count
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages");
        $stats['total_messages'] = $result[0]['count'] ?? 0;
        
        // Chat count
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}chat_configs");
        $stats['total_chats'] = $result[0]['count'] ?? 0;
        
        // Recent activity (last 24 hours)
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages WHERE created_at > NOW() - INTERVAL '24 hours'");
        $stats['messages_24h'] = $result[0]['count'] ?? 0;
        
        // Admin users count
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}admin_users WHERE is_active = TRUE");
        $stats['admin_users'] = $result[0]['count'] ?? 0;
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Failed to get system stats', ['error' => $e->getMessage()]);
        $stats = [
            'total_messages' => 0,
            'total_chats' => 0,
            'messages_24h' => 0,
            'admin_users' => 0
        ];
    }
    
    return $stats;
}

/**
 * Get RAG statistics
 */
function getRAGStats(): array {
    $db = \EDUC\Database\Database::getInstance();
    $prefix = $db->getTablePrefix();
    
    $stats = [];
    
    try {
        // Document count
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents");
        $stats['total_documents'] = $result[0]['count'] ?? 0;
        
        // Embedding count
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}embeddings");
        $stats['total_embeddings'] = $result[0]['count'] ?? 0;
        
        // Processed documents
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status = 'processed'");
        $stats['processed_documents'] = $result[0]['count'] ?? 0;
        
        // Pending documents
        $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status IN ('uploaded', 'pending')");
        $stats['pending_documents'] = $result[0]['count'] ?? 0;
        
        // Document types
        $result = $db->query("SELECT mime_type, COUNT(*) as count FROM {$prefix}documents GROUP BY mime_type ORDER BY count DESC LIMIT 5");
        $stats['document_types'] = $result;
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Failed to get RAG stats', ['error' => $e->getMessage()]);
        $stats = [
            'total_documents' => 0,
            'total_embeddings' => 0,
            'processed_documents' => 0,
            'pending_documents' => 0,
            'document_types' => []
        ];
    }
    
    return $stats;
}

/**
 * Get system information
 */
function getSystemInfo(): array {
    return [
        'php_version' => PHP_VERSION,
        'php_memory_limit' => ini_get('memory_limit'),
        'php_max_execution_time' => ini_get('max_execution_time'),
        'cloudron_mode' => \EDUC\Core\Environment::isCloudron(),
        'app_path' => \EDUC\Core\Environment::getAppPath(),
        'database_type' => 'PostgreSQL',
        'log_level' => \EDUC\Utils\Logger::getLogLevel(),
        'extensions' => [
            'pdo' => extension_loaded('pdo'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'curl' => extension_loaded('curl'),
            'json' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'gd' => extension_loaded('gd'),
            'zip' => extension_loaded('zip'),
            'openssl' => extension_loaded('openssl'),
        ],
        'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
        'disk_usage' => getDiskUsage(),
        'uptime' => getSystemUptime()
    ];
}

/**
 * Get disk usage information
 */
function getDiskUsage(): array {
    $appPath = \EDUC\Core\Environment::getAppPath();
    
    try {
        $totalBytes = disk_total_space($appPath);
        $freeBytes = disk_free_space($appPath);
        $usedBytes = $totalBytes - $freeBytes;
        
        return [
            'total' => formatBytes($totalBytes),
            'used' => formatBytes($usedBytes),
            'free' => formatBytes($freeBytes),
            'usage_percentage' => round(($usedBytes / $totalBytes) * 100, 1)
        ];
    } catch (Exception $e) {
        return [
            'total' => 'Unknown',
            'used' => 'Unknown',
            'free' => 'Unknown',
            'usage_percentage' => 0
        ];
    }
}

/**
 * Get system uptime (for web server)
 */
function getSystemUptime(): string {
    try {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return sprintf('Load: %.2f, %.2f, %.2f', $load[0], $load[1], $load[2]);
        }
        return 'Load average not available';
    } catch (Exception $e) {
        return 'Uptime not available';
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Test database connection
 */
function testDatabaseConnection(): array {
    try {
        $start = microtime(true);
        $db = \EDUC\Database\Database::getInstance();
        $connection = $db->getConnection();
        
        // Test query
        $version = $connection->query('SELECT version()')->fetchColumn();
        $time = round((microtime(true) - $start) * 1000, 2);
        
        // Test pgvector
        $pgvectorAvailable = false;
        try {
            $connection->exec("CREATE EXTENSION IF NOT EXISTS vector");
            $pgvectorAvailable = true;
        } catch (Exception $e) {
            // pgvector not available
        }
        
        return [
            'status' => 'success',
            'connection_time' => $time,
            'version' => substr($version, 0, 80),
            'pgvector' => $pgvectorAvailable
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Test API connection
 */
function testAPIConnection(): array {
    try {
        $apiKey = \EDUC\Core\Environment::get('AI_API_KEY');
        $apiEndpoint = \EDUC\Core\Environment::get('AI_API_ENDPOINT');
        
        if (!$apiKey || !$apiEndpoint) {
            return [
                'status' => 'error',
                'error' => 'Missing AI_API_KEY or AI_API_ENDPOINT environment variables'
            ];
        }
        
        $llmClient = new \EDUC\API\LLMClient(
            $apiKey,
            $apiEndpoint,
            \EDUC\Core\Environment::get('EMBEDDING_API_ENDPOINT'),
            \EDUC\Core\Environment::get('MODELS_API_ENDPOINT')
        );
        
        $result = $llmClient->testConnection();
        return $result;
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get environment variables (sanitized)
 */
function getEnvironmentVariables(): array {
    $envVars = [
        'CLOUDRON_ENVIRONMENT',
        'CLOUDRON_APP_DOMAIN',
        'CLOUDRON_APP_ORIGIN',
        'CLOUDRON_POSTGRESQL_HOST',
        'CLOUDRON_POSTGRESQL_PORT',
        'CLOUDRON_POSTGRESQL_DATABASE',
        'AI_API_ENDPOINT',
        'EMBEDDING_API_ENDPOINT',
        'MODELS_API_ENDPOINT',
        'LOG_LEVEL',
        'DEBUG_MODE'
    ];
    
    $sensitiveVars = [
        'CLOUDRON_POSTGRESQL_USERNAME',
        'CLOUDRON_POSTGRESQL_PASSWORD',
        'AI_API_KEY'
    ];
    
    $result = [];
    
    // Regular vars
    foreach ($envVars as $var) {
        $value = getenv($var);
        $result[$var] = $value ?: 'NOT SET';
    }
    
    // Sensitive vars (show only if set)
    foreach ($sensitiveVars as $var) {
        $value = getenv($var);
        $result[$var] = $value ? '[SET - ' . strlen($value) . ' chars]' : 'NOT SET';
    }
    
    return $result;
}

/**
 * Format timestamp for display
 */
function formatDateTime(?string $timestamp): string {
    if (!$timestamp) {
        return 'Never';
    }
    
    $date = new DateTime($timestamp);
    return $date->format('Y-m-d H:i:s');
}

/**
 * Get relative time
 */
function getRelativeTime(?string $timestamp): string {
    if (!$timestamp) {
        return 'Never';
    }
    
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->days > 7) {
        return $date->format('M j, Y');
    } elseif ($interval->days > 0) {
        return $interval->days . ' day' . ($interval->days > 1 ? 's' : '') . ' ago';
    } elseif ($interval->h > 0) {
        return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    } elseif ($interval->i > 0) {
        return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

/**
 * Generate alert HTML
 */
function renderAlert(string $message, string $type = 'info', bool $dismissible = true): string {
    $alertClass = 'alert-' . ($type === 'error' ? 'danger' : $type);
    $dismissButton = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
    
    return sprintf(
        '<div class="alert %s%s">%s%s</div>',
        $alertClass,
        $dismissible ? ' alert-dismissible fade show' : '',
        htmlspecialchars($message),
        $dismissButton
    );
} 