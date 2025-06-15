<?php

namespace EDUC\Core;

use Exception;

/**
 * Environment variable management with Cloudron compatibility
 * Handles both .env files and Cloudron environment variables
 */
class Environment {
    private static bool $loaded = false;
    private static array $envVars = [];
    
    /**
     * Load environment variables from .env file and/or Cloudron environment
     */
    public static function load(string $envFile = '.env'): void {
        if (self::$loaded) {
            return;
        }
        
        // First, load Cloudron environment variables if available
        self::loadCloudronEnvironment();
        
        // Then try to load .env file (if exists)
        if (file_exists($envFile)) {
            self::loadDotEnvFile($envFile);
        }
        
        // Auto-include Cloudron custom environment if available
        if (file_exists(__DIR__ . '/../../auto-include.php')) {
            require_once __DIR__ . '/../../auto-include.php';
        }
        
        self::$loaded = true;
    }
    
    /**
     * Load Cloudron-specific environment variables
     */
    private static function loadCloudronEnvironment(): void {
        // Check if we're in a Cloudron environment
        if (getenv('CLOUDRON_ENVIRONMENT') !== false) {
            self::$envVars['CLOUDRON_MODE'] = 'true';
            
            // Map Cloudron database variables to our expected format
            if ($dbUrl = getenv('CLOUDRON_POSTGRESQL_URL')) {
                self::$envVars['DATABASE_URL'] = $dbUrl;
            }
            
            if ($dbHost = getenv('CLOUDRON_POSTGRESQL_HOST')) {
                self::$envVars['DB_HOST'] = $dbHost;
            }
            
            if ($dbPort = getenv('CLOUDRON_POSTGRESQL_PORT')) {
                self::$envVars['DB_PORT'] = $dbPort;
            }
            
            if ($dbName = getenv('CLOUDRON_POSTGRESQL_DATABASE')) {
                self::$envVars['DB_NAME'] = $dbName;
            }
            
            if ($dbUser = getenv('CLOUDRON_POSTGRESQL_USERNAME')) {
                self::$envVars['DB_USER'] = $dbUser;
            }
            
            if ($dbPassword = getenv('CLOUDRON_POSTGRESQL_PASSWORD')) {
                self::$envVars['DB_PASSWORD'] = $dbPassword;
            }
            
            // App context variables
            if ($appName = getenv('APP_NAME')) {
                self::$envVars['APP_NAME'] = $appName;
            }
            
            if ($appId = getenv('APP_ID')) {
                self::$envVars['APP_ID'] = $appId;
            }
            
            if ($appDir = getenv('APP_DIRECTORY')) {
                self::$envVars['APP_DIRECTORY'] = $appDir;
            }
            
            if ($appDomain = getenv('CLOUDRON_APP_DOMAIN')) {
                self::$envVars['APP_DOMAIN'] = $appDomain;
            }
            
            if ($appOrigin = getenv('CLOUDRON_APP_ORIGIN')) {
                self::$envVars['APP_ORIGIN'] = $appOrigin;
            }
        }
    }
    
    /**
     * Load variables from .env file
     */
    private static function loadDotEnvFile(string $envFile): void {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines === false) {
            throw new Exception("Could not read environment file: {$envFile}");
        }
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                self::$envVars[$key] = $value;
                
                // Also set in $_ENV for compatibility
                $_ENV[$key] = $value;
            }
        }
    }
    
    /**
     * Get an environment variable value
     */
    public static function get(string $key, ?string $default = null): ?string {
        // First check our loaded variables
        if (isset(self::$envVars[$key])) {
            return self::$envVars[$key];
        }
        
        // Then check $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        
        // Finally check getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Set an environment variable
     */
    public static function set(string $key, string $value): void {
        self::$envVars[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
    
    /**
     * Check if an environment variable exists
     */
    public static function has(string $key): bool {
        return isset(self::$envVars[$key]) || 
               isset($_ENV[$key]) || 
               getenv($key) !== false;
    }
    
    /**
     * Get all environment variables
     */
    public static function all(): array {
        return array_merge($_ENV, self::$envVars);
    }
    
    /**
     * Check if running in Cloudron environment
     */
    public static function isCloudron(): bool {
        return self::get('CLOUDRON_MODE') === 'true' || 
               getenv('CLOUDRON_ENVIRONMENT') !== false;
    }
    
    /**
     * Get the application directory path for Cloudron deployments
     */
    public static function getAppPath(): string {
        if (self::isCloudron()) {
            $appDir = self::get('APP_DIRECTORY');
            if ($appDir) {
                return "/app/code/apps/{$appDir}";
            }
        }
        
        return __DIR__ . '/../..';
    }
    
    /**
     * Get the uploads directory path
     */
    public static function getUploadsPath(): string {
        $uploadDir = self::get('UPLOAD_DIR', 'uploads');
        return self::getAppPath() . '/' . $uploadDir;
    }
    
    /**
     * Get the logs directory path
     */
    public static function getLogsPath(): string {
        return self::getAppPath() . '/logs';
    }
    
    /**
     * Get the cache directory path
     */
    public static function getCachePath(): string {
        return self::getAppPath() . '/cache';
    }
}
?> 