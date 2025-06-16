<?php
/**
 * Admin Panel Shared Functions
 */

require_once __DIR__ . '/auth.php';

/**
 * Get system statistics
 */
function getSystemStats(): array {
    $stats = [
        'total_messages' => 0,
        'total_chats' => 0,
        'messages_24h' => 0,
        'admin_users' => 1
    ];
    
    try {
        $db = \EDUC\Database\Database::getInstance();
        $prefix = $db->getTablePrefix();
        
        // Check if tables exist before querying them
        $connection = $db->getConnection();
        
        // Check if messages table exists
        $tableExists = $connection->prepare("SELECT to_regclass(?)");
        $tableExists->execute(["{$prefix}messages"]);
        if ($tableExists->fetchColumn()) {
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages");
            $stats['total_messages'] = $result[0]['count'] ?? 0;
            
            // Recent activity (last 24 hours)
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages WHERE created_at > NOW() - INTERVAL '24 hours'");
            $stats['messages_24h'] = $result[0]['count'] ?? 0;
        }
        
        // Check if chat_configs table exists
        $tableExists = $connection->prepare("SELECT to_regclass(?)");
        $tableExists->execute(["{$prefix}chat_configs"]);
        if ($tableExists->fetchColumn()) {
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}chat_configs");
            $stats['total_chats'] = $result[0]['count'] ?? 0;
        }
        
        // Check if admin_users table exists
        $tableExists = $connection->prepare("SELECT to_regclass(?)");
        $tableExists->execute(["{$prefix}admin_users"]);
        if ($tableExists->fetchColumn()) {
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}admin_users WHERE is_active = TRUE");
            $stats['admin_users'] = $result[0]['count'] ?? 1;
        }
        
    } catch (Exception $e) {
        error_log('Failed to get system stats: ' . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get RAG statistics
 */
function getRAGStats(): array {
    $stats = [
        'total_documents' => 0,
        'total_embeddings' => 0,
        'processed_documents' => 0,
        'pending_documents' => 0,
        'document_types' => []
    ];
    
    try {
        $db = \EDUC\Database\Database::getInstance();
        $prefix = $db->getTablePrefix();
        $connection = $db->getConnection();
        
        // Check if documents table exists
        $tableExists = $connection->prepare("SELECT to_regclass(?)");
        $tableExists->execute(["{$prefix}documents"]);
        if ($tableExists->fetchColumn()) {
            // Document count
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents");
            $stats['total_documents'] = $result[0]['count'] ?? 0;
            
            // Processed documents
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status = 'processed'");
            $stats['processed_documents'] = $result[0]['count'] ?? 0;
            
            // Pending documents
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status IN ('uploaded', 'pending')");
            $stats['pending_documents'] = $result[0]['count'] ?? 0;
            
            // Document types
            $result = $db->query("SELECT mime_type, COUNT(*) as count FROM {$prefix}documents GROUP BY mime_type ORDER BY count DESC LIMIT 5");
            $stats['document_types'] = $result;
        }
        
        // Check if embeddings table exists
        $tableExists = $connection->prepare("SELECT to_regclass(?)");
        $tableExists->execute(["{$prefix}embeddings"]);
        if ($tableExists->fetchColumn()) {
            // Embedding count
            $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}embeddings");
            $stats['total_embeddings'] = $result[0]['count'] ?? 0;
        }
        
    } catch (Exception $e) {
        error_log('Failed to get RAG stats: ' . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get system information
 */
function getSystemInfo(): array {
    $info = [
        'php_version' => PHP_VERSION,
        'php_memory_limit' => ini_get('memory_limit'),
        'php_max_execution_time' => ini_get('max_execution_time'),
        'cloudron_mode' => !empty(getenv('CLOUDRON_ENVIRONMENT')),
        'app_path' => __DIR__,
        'database_type' => 'PostgreSQL',
        'log_level' => 'INFO',
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
        'disk_usage' => ['usage_percentage' => 0, 'used' => '0 MB', 'total' => '0 MB'],
        'uptime' => 'Unknown'
    ];
    
    try {
        // Try to get more accurate information if classes are available
        if (class_exists('\EDUC\Core\Environment')) {
            $info['cloudron_mode'] = \EDUC\Core\Environment::isCloudron();
            $info['app_path'] = \EDUC\Core\Environment::getAppPath();
        }
        
        if (class_exists('\EDUC\Utils\Logger')) {
            $info['log_level'] = \EDUC\Utils\Logger::getLogLevel();
        }
        
        $info['disk_usage'] = getDiskUsage();
        $info['uptime'] = getSystemUptime();
        
    } catch (Exception $e) {
        error_log('Failed to get complete system info: ' . $e->getMessage());
    }
    
    return $info;
}

/**
 * Get disk usage information
 */
function getDiskUsage(): array {
    try {
        $appPath = __DIR__;
        if (class_exists('\EDUC\Core\Environment')) {
            $appPath = \EDUC\Core\Environment::getAppPath();
        }
        
        $totalBytes = disk_total_space($appPath);
        $freeBytes = disk_free_space($appPath);
        
        if ($totalBytes && $freeBytes) {
            $usedBytes = $totalBytes - $freeBytes;
            
            return [
                'total' => formatBytes($totalBytes),
                'used' => formatBytes($usedBytes),
                'free' => formatBytes($freeBytes),
                'usage_percentage' => round(($usedBytes / $totalBytes) * 100, 1)
            ];
        }
    } catch (Exception $e) {
        error_log('Failed to get disk usage: ' . $e->getMessage());
    }
    
    return [
        'total' => 'Unknown',
        'used' => 'Unknown',
        'free' => 'Unknown',
        'usage_percentage' => 0
    ];
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
        $apiKey = getenv('AI_API_KEY');
        $apiEndpoint = getenv('AI_API_ENDPOINT');
        
        if (!$apiKey || !$apiEndpoint) {
            return [
                'status' => 'error',
                'error' => 'Missing AI_API_KEY or AI_API_ENDPOINT environment variables'
            ];
        }
        
        // Simple cURL test instead of using the LLMClient class
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => str_replace('/chat/completions', '/models', $apiEndpoint),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return [
                'status' => 'success',
                'message' => 'API connection successful'
            ];
        } else {
            return [
                'status' => 'error',
                'error' => 'API returned HTTP ' . $httpCode
            ];
        }
        
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

/**
 * Get available AI models from GWDG SAIA API
 */
function getAvailableModels(): array {
    try {
        $apiKey = getenv('AI_API_KEY');
        $modelsEndpoint = getenv('MODELS_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/models';
        
        if (!$apiKey) {
            throw new Exception('AI_API_KEY not configured');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $modelsEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('API returned HTTP ' . $httpCode);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['data'])) {
            throw new Exception('Invalid API response format');
        }
        
        return $data['data'];
        
    } catch (Exception $e) {
        error_log('Failed to fetch models: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get current setting value
 */
function getCurrentSetting(string $key): ?string {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $settings = $db->getAllSettings();
        return $settings[$key]['value'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Format file size for display
 */
function formatFileSize(int $bytes): string {
    return formatBytes($bytes);
}

/**
 * Check pgvector extension availability
 */
function checkPgvectorExtension(): bool {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $connection = $db->getConnection();
        $connection->exec("CREATE EXTENSION IF NOT EXISTS vector");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Handle file upload for RAG documents
 */
function handleFileUpload(array $files): array {
    if (!isset($files['documents']) || empty($files['documents']['name'][0])) {
        return ['message' => 'No files selected', 'type' => 'danger'];
    }
    
    // This is a placeholder implementation
    return [
        'message' => 'File upload functionality needs to be fully implemented',
        'type' => 'info'
    ];
}

/**
 * Process documents for RAG
 */
function processDocuments(): array {
    // This is a placeholder implementation
    return [
        'message' => 'Document processing functionality needs to be implemented',
        'type' => 'info'
    ];
}

/**
 * Clear RAG data
 */
function clearRAGData(): array {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $prefix = $db->getTablePrefix();
        
        // Clear embeddings and documents (when tables exist)
        // $db->execute("DELETE FROM {$prefix}embeddings");
        // $db->execute("DELETE FROM {$prefix}documents");
        
        return [
            'message' => 'RAG data clearing functionality needs to be implemented',
            'type' => 'info'
        ];
    } catch (Exception $e) {
        return [
            'message' => 'Failed to clear RAG data: ' . $e->getMessage(),
            'type' => 'danger'
        ];
    }
}

/**
 * Get uploaded documents list
 */
function getUploadedDocuments(): array {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $prefix = $db->getTablePrefix();
        
        // When documents table exists:
        // $result = $db->query("SELECT * FROM {$prefix}documents ORDER BY created_at DESC");
        // return $result;
        
        return []; // Empty for now
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get current user information
 */
function getCurrentUser(): array {
    return [
        'id' => 1,
        'username' => 'admin',
        'full_name' => 'Administrator',
        'email' => 'admin@example.com',
        'role' => 'administrator'
    ];
}
?> 