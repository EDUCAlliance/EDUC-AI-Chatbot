<?php

namespace EDUC\Utils;

use Exception;
use EDUC\Core\Environment;

/**
 * Security utility class for error handling and security features
 */
class Security {
    private static bool $initialized = false;
    
    /**
     * Initialize error handlers and security measures
     */
    public static function initializeErrorHandlers(): void {
        if (self::$initialized) {
            return;
        }
        
        // Set error reporting based on environment
        if (Environment::get('CLOUDRON_ENVIRONMENT', 'production') === 'production') {
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        }
        
        // Set custom error handler
        set_error_handler([self::class, 'errorHandler']);
        
        // Set custom exception handler
        set_exception_handler([self::class, 'exceptionHandler']);
        
        // Set shutdown function for fatal errors
        register_shutdown_function([self::class, 'shutdownHandler']);
        
        self::$initialized = true;
        
        Logger::info('Security error handlers initialized');
    }
    
    /**
     * Custom error handler
     */
    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED'
        ];
        
        $type = $errorTypes[$errno] ?? 'UNKNOWN';
        
        Logger::error("PHP {$type}: {$errstr}", [
            'file' => $errfile,
            'line' => $errline,
            'error_code' => $errno
        ]);
        
        // Don't execute PHP's internal error handler
        return true;
    }
    
    /**
     * Custom exception handler
     */
    public static function exceptionHandler(Exception $exception): void {
        Logger::critical('Uncaught exception: ' . $exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'exception_class' => get_class($exception)
        ]);
        
        // In production, show generic error message
        if (Environment::get('CLOUDRON_ENVIRONMENT', 'production') === 'production') {
            http_response_code(500);
            die('Internal server error');
        } else {
            // In development, show detailed error
            echo '<h1>Uncaught Exception</h1>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '</p>';
            echo '<p><strong>Line:</strong> ' . $exception->getLine() . '</p>';
            echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
        }
    }
    
    /**
     * Shutdown handler for fatal errors
     */
    public static function shutdownHandler(): void {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            Logger::critical('Fatal error occurred', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
        }
    }
    
    /**
     * Sanitize input data
     */
    public static function sanitizeInput(string $input, bool $allowHtml = false): string {
        if (!$allowHtml) {
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
        
        // Basic HTML sanitization - remove script tags and dangerous attributes
        $input = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $input);
        $input = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi', '', $input);
        $input = preg_replace('/\s*javascript\s*:/i', '', $input);
        $input = preg_replace('/\s*vbscript\s*:/i', '', $input);
        $input = preg_replace('/\s*on\w+\s*=/i', '', $input);
        
        return trim($input);
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCSRFToken(string $token): bool {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate signature for webhook
     */
    public static function validateWebhookSignature(string $payload, string $signature, string $secret): bool {
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, strtolower($signature));
    }
    
    /**
     * Rate limiting check
     */
    public static function checkRateLimit(string $key, int $limit = 100, int $window = 3600): bool {
        $currentTime = time();
        $windowStart = $currentTime - $window;
        
        // Clean up old entries
        if (!isset($_SESSION['rate_limits'])) {
            $_SESSION['rate_limits'] = [];
        }
        
        if (!isset($_SESSION['rate_limits'][$key])) {
            $_SESSION['rate_limits'][$key] = [];
        }
        
        // Remove old timestamps
        $_SESSION['rate_limits'][$key] = array_filter(
            $_SESSION['rate_limits'][$key],
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        // Check if limit exceeded
        if (count($_SESSION['rate_limits'][$key]) >= $limit) {
            return false;
        }
        
        // Add current timestamp
        $_SESSION['rate_limits'][$key][] = $currentTime;
        
        return true;
    }
    
    /**
     * Sanitize environment variable for logging
     */
    public static function sanitizeEnvVar(string $key, string $value): string {
        $sensitiveKeywords = [
            'PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'PRIVATE', 'CREDENTIAL',
            'AUTH', 'API_KEY', 'DATABASE_URL', 'DSN'
        ];
        
        foreach ($sensitiveKeywords as $keyword) {
            if (stripos($key, $keyword) !== false) {
                return '[REDACTED]';
            }
        }
        
        return $value;
    }
    
    /**
     * Validate input against rules
     */
    public static function validateInput(array $data, array $rules): array {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            if ($rule['required'] && empty($value)) {
                $errors[$field] = "Field {$field} is required";
                continue;
            }
            
            if (!empty($value)) {
                if (isset($rule['type'])) {
                    switch ($rule['type']) {
                        case 'email':
                            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                $errors[$field] = "Invalid email format";
                            }
                            break;
                        case 'url':
                            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                                $errors[$field] = "Invalid URL format";
                            }
                            break;
                        case 'int':
                            if (!filter_var($value, FILTER_VALIDATE_INT)) {
                                $errors[$field] = "Must be an integer";
                            }
                            break;
                        case 'float':
                            if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
                                $errors[$field] = "Must be a number";
                            }
                            break;
                        case 'bool':
                            if (!in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'])) {
                                $errors[$field] = "Must be a boolean value";
                            }
                            break;
                    }
                }
                
                if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                    $errors[$field] = "Minimum length is {$rule['min_length']} characters";
                }
                
                if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                    $errors[$field] = "Maximum length is {$rule['max_length']} characters";
                }
                
                if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                    $errors[$field] = $rule['pattern_message'] ?? "Invalid format";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Check if request is from authorized source
     */
    public static function isAuthorizedRequest(): bool {
        // Check for basic security headers
        $requiredHeaders = ['HTTP_USER_AGENT', 'HTTP_HOST'];
        
        foreach ($requiredHeaders as $header) {
            if (!isset($_SERVER[$header])) {
                return false;
            }
        }
        
        // Check if coming from expected domain (if configured)
        $allowedDomains = Environment::get('ALLOWED_DOMAINS');
        if ($allowedDomains) {
            $domains = explode(',', $allowedDomains);
            $host = $_SERVER['HTTP_HOST'] ?? '';
            
            $isAllowed = false;
            foreach ($domains as $domain) {
                if (strpos($host, trim($domain)) !== false) {
                    $isAllowed = true;
                    break;
                }
            }
            
            if (!$isAllowed) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Log security event
     */
    public static function logSecurityEvent(string $event, array $context = []): void {
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $context['request_uri'] = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        Logger::warning("Security event: {$event}", $context);
    }
}
?> 