<?php

namespace EDUC\Utils;

use Exception;
use EDUC\Core\Environment;

/**
 * Logger utility class for application logging
 * Compatible with Cloudron deployment environment
 */
class Logger {
    private static bool $initialized = false;
    private static string $logPath = '';
    private static string $logLevel = 'INFO';
    private static array $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    /**
     * Initialize the logger
     */
    public static function initialize(): void {
        if (self::$initialized) {
            return;
        }
        
        // Set log path based on environment
        self::$logPath = Environment::getLogsPath();
        
        // Create logs directory if it doesn't exist
        if (!is_dir(self::$logPath)) {
            mkdir(self::$logPath, 0755, true);
        }
        
        // Set log level from environment
        self::$logLevel = strtoupper(Environment::get('LOG_LEVEL', 'INFO'));
        
        self::$initialized = true;
        
        self::info('Logger initialized', [
            'log_path' => self::$logPath,
            'log_level' => self::$logLevel
        ]);
    }
    
    /**
     * Log debug message
     */
    public static function debug(string $message, array $context = []): void {
        self::log('DEBUG', $message, $context);
    }
    
    /**
     * Log info message
     */
    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }
    
    /**
     * Log error message
     */
    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }
    
    /**
     * Log critical message
     */
    public static function critical(string $message, array $context = []): void {
        self::log('CRITICAL', $message, $context);
    }
    
    /**
     * Log message with specified level
     */
    public static function log(string $level, string $message, array $context = []): void {
        if (!self::$initialized) {
            self::initialize();
        }
        
        // Check if log level is enabled
        $currentLevelValue = self::$logLevels[self::$logLevel] ?? 1;
        $messageLevelValue = self::$logLevels[$level] ?? 1;
        
        if ($messageLevelValue < $currentLevelValue) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $appName = Environment::get('APP_NAME', 'EDUC-Chatbot');
        $pid = getmypid();
        
        // Sanitize context for logging
        $sanitizedContext = self::sanitizeContext($context);
        
        // Format log entry
        $logEntry = "[{$timestamp}] {$appName}.{$level} (PID:{$pid}): {$message}";
        
        if (!empty($sanitizedContext)) {
            $logEntry .= ' ' . json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES);
        }
        
        $logEntry .= PHP_EOL;
        
        // Write to log file
        $logFile = self::$logPath . '/' . date('Y-m-d') . '.log';
        
        try {
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
            // Also write to error_log for critical errors
            if ($level === 'CRITICAL' || $level === 'ERROR') {
                error_log("EDUC-Chatbot {$level}: {$message}");
            }
            
        } catch (Exception $e) {
            // Fallback to error_log if file writing fails
            error_log("Logger write failed: " . $e->getMessage());
            error_log("Original message: {$logEntry}");
        }
    }
    
    /**
     * Sanitize context data for logging (remove sensitive information)
     */
    private static function sanitizeContext(array $context): array {
        $sensitiveKeys = [
            'password',
            'token',
            'key',
            'secret',
            'auth',
            'authorization',
            'api_key',
            'private',
            'credential'
        ];
        
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Check if key contains sensitive keywords
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeContext($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                // Truncate very long strings
                $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Log HTTP request details
     */
    public static function logRequest(string $method, string $url, array $headers = [], array $body = []): void {
        self::info('HTTP Request', [
            'method' => $method,
            'url' => $url,
            'headers' => self::sanitizeHeaders($headers),
            'body_size' => is_array($body) ? count($body) : strlen((string)$body)
        ]);
    }
    
    /**
     * Log HTTP response details
     */
    public static function logResponse(int $statusCode, array $headers = [], $body = null): void {
        self::info('HTTP Response', [
            'status_code' => $statusCode,
            'headers' => self::sanitizeHeaders($headers),
            'body_size' => is_string($body) ? strlen($body) : (is_array($body) ? count($body) : 0)
        ]);
    }
    
    /**
     * Sanitize HTTP headers for logging
     */
    private static function sanitizeHeaders(array $headers): array {
        $sanitized = [];
        
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            
            if (strpos($lowerKey, 'authorization') !== false ||
                strpos($lowerKey, 'token') !== false ||
                strpos($lowerKey, 'key') !== false) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get current log level
     */
    public static function getLogLevel(): string {
        return self::$logLevel;
    }
    
    /**
     * Set log level
     */
    public static function setLogLevel(string $level): void {
        $level = strtoupper($level);
        if (isset(self::$logLevels[$level])) {
            self::$logLevel = $level;
            self::info('Log level changed', ['new_level' => $level]);
        }
    }
    
    /**
     * Get log file path for a specific date
     */
    public static function getLogFile(string $date = null): string {
        if (!self::$initialized) {
            self::initialize();
        }
        
        $date = $date ?: date('Y-m-d');
        return self::$logPath . '/' . $date . '.log';
    }
    
    /**
     * Get recent log entries
     */
    public static function getRecentLogs(int $lines = 100, string $level = null): array {
        $logFile = self::getLogFile();
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $logs = [];
        $handle = fopen($logFile, 'r');
        
        if ($handle) {
            // Read file backwards to get most recent entries first
            $fileSize = filesize($logFile);
            $pos = $fileSize;
            $buffer = '';
            $lineCount = 0;
            
            while ($pos > 0 && $lineCount < $lines) {
                $chunkSize = min(4096, $pos);
                $pos -= $chunkSize;
                fseek($handle, $pos);
                $chunk = fread($handle, $chunkSize);
                $buffer = $chunk . $buffer;
                
                $lines_in_buffer = explode("\n", $buffer);
                $buffer = array_shift($lines_in_buffer);
                
                foreach (array_reverse($lines_in_buffer) as $line) {
                    if (!empty(trim($line))) {
                        if ($level === null || strpos($line, ".{$level} ") !== false) {
                            array_unshift($logs, trim($line));
                            $lineCount++;
                            
                            if ($lineCount >= $lines) {
                                break;
                            }
                        }
                    }
                }
            }
            
            fclose($handle);
        }
        
        return array_slice($logs, 0, $lines);
    }
    
    /**
     * Clear old log files
     */
    public static function cleanup(int $daysToKeep = 30): int {
        if (!self::$initialized) {
            self::initialize();
        }
        
        $deletedFiles = 0;
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));
        
        $logFiles = glob(self::$logPath . '/*.log');
        
        foreach ($logFiles as $logFile) {
            $filename = basename($logFile, '.log');
            
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filename) && $filename < $cutoffDate) {
                if (unlink($logFile)) {
                    $deletedFiles++;
                }
            }
        }
        
        if ($deletedFiles > 0) {
            self::info('Log cleanup completed', [
                'deleted_files' => $deletedFiles,
                'days_to_keep' => $daysToKeep
            ]);
        }
        
        return $deletedFiles;
    }
}
?> 