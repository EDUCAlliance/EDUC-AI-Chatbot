<?php

declare(strict_types=1);

namespace EducBot\Helpers;

use PDO;
use PDOException;

/**
 * Logger Helper Class
 * 
 * Provides centralized logging functionality with support for both
 * database and file logging. Automatically adapts based on environment.
 */
class Logger
{
    private static ?self $instance = null;
    private ?PDO $db = null;
    private string $logDir;
    private string $logLevel;
    private bool $initialized = false;

    private const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4,
    ];

    private function __construct()
    {
        $this->logDir = __DIR__ . '/../../logs';
        $this->logLevel = strtoupper(getenv('LOG_LEVEL') ?: 'INFO');
        
        // Ensure log directory exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public static function initialize(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        if (!self::$instance->initialized) {
            self::$instance->setupDatabase();
            self::$instance->initialized = true;
        }
    }

    private function setupDatabase(): void
    {
        try {
            if (function_exists('getDbConnection')) {
                $this->db = getDbConnection();
            }
        } catch (\Exception $e) {
            // Database not available, fall back to file logging only
            $this->logToFile('WARNING', 'Database logging unavailable, using file logging only', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('CRITICAL', $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (self::$instance === null) {
            self::initialize();
        }

        $level = strtoupper($level);
        
        // Check if this level should be logged
        if (!self::shouldLog($level)) {
            return;
        }

        self::$instance->writeLog($level, $message, $context);
    }

    private static function shouldLog(string $level): bool
    {
        $currentLevel = self::LEVELS[self::$instance->logLevel] ?? 1;
        $messageLevel = self::LEVELS[$level] ?? 1;
        
        return $messageLevel >= $currentLevel;
    }

    private function writeLog(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        
        // Sanitize context for logging
        $sanitizedContext = $this->sanitizeContext($context);
        
        // Try database logging first
        if ($this->db !== null) {
            try {
                $this->logToDatabase($level, $message, $sanitizedContext);
            } catch (PDOException $e) {
                // Database logging failed, fall back to file
                $this->logToFile('ERROR', 'Database logging failed', ['error' => $e->getMessage()]);
                $this->logToFile($level, $message, $sanitizedContext);
            }
        } else {
            // Database not available, use file logging
            $this->logToFile($level, $message, $sanitizedContext);
        }
    }

    private function logToDatabase(string $level, string $message, array $context): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)'
        );
        
        $stmt->execute([
            $level,
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function logToFile(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $date = date('Y-m-d');
        $logFile = $this->logDir . '/' . $date . '.log';
        
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[{$timestamp}] {$level}: {$message}{$contextStr}" . PHP_EOL;
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            // Sanitize sensitive information
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                // Truncate very long strings
                $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $sensitiveKeywords = [
            'password', 'secret', 'token', 'key', 'auth', 'credential',
            'BOT_TOKEN', 'AI_API_KEY', 'DATABASE_URL', 'CLOUDRON_POSTGRESQL_PASSWORD'
        ];
        
        $lowerKey = strtolower($key);
        
        foreach ($sensitiveKeywords as $keyword) {
            if (stripos($lowerKey, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get recent logs for admin panel
     */
    public static function getRecentLogs(int $limit = 100, ?string $level = null): array
    {
        if (self::$instance === null) {
            self::initialize();
        }

        if (self::$instance->db === null) {
            return self::getRecentLogsFromFile($limit, $level);
        }

        try {
            $query = 'SELECT level, message, context, created_at FROM bot_logs';
            $params = [];
            
            if ($level !== null) {
                $query .= ' WHERE level = ?';
                $params[] = strtoupper($level);
            }
            
            $query .= ' ORDER BY created_at DESC LIMIT ?';
            $params[] = $limit;
            
            $stmt = self::$instance->db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            return self::getRecentLogsFromFile($limit, $level);
        }
    }

    private static function getRecentLogsFromFile(int $limit, ?string $level = null): array
    {
        $logs = [];
        $logFiles = glob(self::$instance->logDir . '/*.log');
        
        // Sort files by date (newest first)
        rsort($logFiles);
        
        foreach ($logFiles as $file) {
            if (count($logs) >= $limit) {
                break;
            }
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            
            // Read lines in reverse order
            $lines = array_reverse($lines);
            
            foreach ($lines as $line) {
                if (count($logs) >= $limit) {
                    break;
                }
                
                if (preg_match('/^\[([^\]]+)\] ([A-Z]+): (.+)$/', $line, $matches)) {
                    $logLevel = $matches[2];
                    
                    if ($level === null || $logLevel === strtoupper($level)) {
                        $logs[] = [
                            'created_at' => $matches[1],
                            'level' => $logLevel,
                            'message' => $matches[3],
                            'context' => '{}' // File logs don't separate context easily
                        ];
                    }
                }
            }
        }
        
        return $logs;
    }

    /**
     * Clean old logs to prevent disk space issues
     */
    public static function cleanOldLogs(int $daysToKeep = 30): void
    {
        if (self::$instance === null) {
            self::initialize();
        }

        // Clean database logs
        if (self::$instance->db !== null) {
            try {
                $stmt = self::$instance->db->prepare(
                    'DELETE FROM bot_logs WHERE created_at < NOW() - INTERVAL ? DAY'
                );
                $stmt->execute([$daysToKeep]);
            } catch (PDOException $e) {
                self::error('Failed to clean database logs', ['error' => $e->getMessage()]);
            }
        }

        // Clean file logs
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));
        $logFiles = glob(self::$instance->logDir . '/*.log');
        
        foreach ($logFiles as $file) {
            $filename = basename($file, '.log');
            if ($filename < $cutoffDate) {
                unlink($file);
            }
        }
    }
} 