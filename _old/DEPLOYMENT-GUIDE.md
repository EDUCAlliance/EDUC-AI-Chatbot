# PHP Git App Manager - Deployment Guide

## Overview

This system provides a managed environment for deploying PHP applications from Git repositories on Cloudron. Each deployed app runs within the same container but maintains isolation through directory structure and database prefixes.

## Environment Variables Available

### Core Cloudron Variables

All deployed PHP applications automatically have access to these Cloudron environment variables:

#### Database Connection
```bash
CLOUDRON_POSTGRESQL_HOST=postgresql
CLOUDRON_POSTGRESQL_PORT=5432
CLOUDRON_POSTGRESQL_DATABASE=dbf63610bfdc2545d2bfcc486c0ba4824c
CLOUDRON_POSTGRESQL_USERNAME=userf63610bfdc2545d2bfcc486c0ba4824c
CLOUDRON_POSTGRESQL_PASSWORD=[generated_password]
CLOUDRON_POSTGRESQL_URL=postgres://userf63610bfdc2545d2bfcc486c0ba4824c:...
```

#### Application Context
```bash
CLOUDRON_APP_DOMAIN=ai.cloudron.myownapp.net
CLOUDRON_APP_ORIGIN=https://ai.cloudron.myownapp.net
APP_NAME=[your_app_name]
APP_ID=[unique_app_id]
APP_DIRECTORY=[app_directory_name]
```

#### System Environment
```bash
CLOUDRON_ENVIRONMENT=production
PHP_VERSION=8.1
APACHE_VERSION=2.4
DOCUMENT_ROOT=/app/code/apps/[app_directory]
```

### Database Connection Examples

#### Option 1: Using Connection URL
```php
<?php
$databaseUrl = getenv('CLOUDRON_POSTGRESQL_URL');
try {
    $db = new PDO($databaseUrl);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
```

#### Option 2: Using Individual Parameters
```php
<?php
$host = getenv('CLOUDRON_POSTGRESQL_HOST');
$port = getenv('CLOUDRON_POSTGRESQL_PORT');
$database = getenv('CLOUDRON_POSTGRESQL_DATABASE');
$username = getenv('CLOUDRON_POSTGRESQL_USERNAME');
$password = getenv('CLOUDRON_POSTGRESQL_PASSWORD');

$dsn = "pgsql:host={$host};port={$port};dbname={$database}";
try {
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
```

#### Option 3: Reusing Manager's Database Config
```php
<?php
// Include the main database configuration
require_once '/app/code/public/config/database.php';

// Use the same connection function
$db = getDbConnection();
?>
```

### Database Schema Best Practices

#### 1. Use Table Prefixes
```php
<?php
function initializeAppDatabase($db, $appPrefix = 'myapp') {
    // Create app-specific tables with prefixes
    $db->exec("
        CREATE TABLE IF NOT EXISTS {$appPrefix}_users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS {$appPrefix}_posts (
            id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES {$appPrefix}_users(id),
            title VARCHAR(255) NOT NULL,
            content TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

// Initialize on first run
initializeAppDatabase($db, 'blog');
?>
```

#### 2. Schema Isolation Example
```php
<?php
class DatabaseManager {
    private $db;
    private $prefix;
    
    public function __construct($tablePrefix = 'app') {
        $this->db = getDbConnection();
        $this->prefix = $tablePrefix . '_';
    }
    
    public function createUser($username, $email) {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->prefix}users (username, email) 
            VALUES (?, ?)
        ");
        return $stmt->execute([$username, $email]);
    }
    
    public function getUsers() {
        $stmt = $this->db->query("SELECT * FROM {$this->prefix}users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }
}

// Usage
$manager = new DatabaseManager('myblog');
$users = $manager->getUsers();
?>
```

### Vector Embeddings Support

All deployed apps can use PostgreSQL's pgvector extension:

```php
<?php
function addEmbedding($db, $content, $embedding, $appPrefix = 'myapp') {
    $stmt = $db->prepare("
        INSERT INTO {$appPrefix}_embeddings (content, metadata, embedding) 
        VALUES (?, ?, ?)
    ");
    return $stmt->execute([
        $content,
        json_encode(['app' => $appPrefix, 'timestamp' => time()]),
        '[' . implode(',', $embedding) . ']' // Convert array to PostgreSQL array format
    ]);
}

function findSimilarContent($db, $embedding, $appPrefix = 'myapp', $limit = 5) {
    $embeddingStr = '[' . implode(',', $embedding) . ']';
    $stmt = $db->prepare("
        SELECT content, metadata, embedding <-> ? as distance 
        FROM {$appPrefix}_embeddings 
        ORDER BY embedding <-> ? 
        LIMIT ?
    ");
    $stmt->execute([$embeddingStr, $embeddingStr, $limit]);
    return $stmt->fetchAll();
}
?>
```

### Application Context Variables

Additionally, each deployed app receives these context variables:

```php
<?php
$appName = $_ENV['APP_NAME']; // Name of the deployed application
$appId = $_ENV['APP_ID'];     // Unique ID of the deployed application
$appDirectory = $_ENV['APP_DIRECTORY']; // App directory name

echo "Running in app: {$appName} (ID: {$appId})";
?>
```

### Custom Environment Variables

Following [Cloudron's configuration patterns](https://forum.cloudron.io/topic/7378/configuring-environment-variables), administrators can define custom environment variables through the admin panel that are automatically available to all deployed applications.

#### Automatic Integration

When applications are deployed, the system automatically:
1. Creates a `custom-env.php` file with all custom environment variables
2. Creates an `auto-include.php` file for easy integration  
3. Modifies the application's `index.php` to auto-load these variables

#### Manual Integration (if auto-include fails)

If you need to manually include custom environment variables in your application:

```php
<?php
// At the top of your application's index.php
require_once __DIR__ . '/auto-include.php';

// Now access custom variables
$apiKey = $_ENV['API_KEY'] ?? 'default_key';
$customSetting = getenv('CUSTOM_SETTING') ?: 'default_value';
?>
```

#### Available Variables

Custom environment variables are accessible through multiple methods:

```php
<?php
// Method 1: $_ENV superglobal
$value = $_ENV['MY_CUSTOM_VAR'];

// Method 2: getenv() function  
$value = getenv('MY_CUSTOM_VAR');

// Method 3: With fallback defaults
$value = $_ENV['MY_CUSTOM_VAR'] ?? 'default_value';
$value = getenv('MY_CUSTOM_VAR') ?: 'fallback_value';

// Check if variable exists
if (isset($_ENV['MY_CUSTOM_VAR'])) {
    // Variable is set
}

if (getenv('MY_CUSTOM_VAR') !== false) {
    // Variable exists
}
?>
```

#### Environment Variable Types

**Regular Variables:**
```php
<?php
$apiUrl = $_ENV['API_URL']; // https://api.example.com
$debugMode = $_ENV['DEBUG_MODE']; // true/false  
$maxRetries = (int)$_ENV['MAX_RETRIES']; // Convert to int
?>
```

**Sensitive Variables** (marked as sensitive in admin panel):
- Values are hidden in logs and debug output
- Still accessible normally in application code
- Useful for API keys, passwords, tokens

#### Management

Custom environment variables are managed through the admin panel at `/server-admin/?action=settings`:

- **Add Variables**: Define new global environment variables
- **Edit Variables**: Update existing variable values and descriptions
- **Delete Variables**: Remove variables (affects all deployed apps)
- **Sensitive Flag**: Mark variables as sensitive to hide values in logs

#### Best Practices

1. **Use descriptive names**: `API_BASE_URL` instead of `URL`
2. **Follow naming conventions**: Use UPPERCASE with underscores
3. **Provide defaults**: Always use fallback values in your code
4. **Document usage**: Add descriptions when creating variables
5. **Mark sensitive data**: Flag API keys, passwords as sensitive

```php
<?php
// Good practices example
$apiKey = $_ENV['THIRD_PARTY_API_KEY'] ?? throw new Exception('API_KEY required');
$timeout = (int)($_ENV['HTTP_TIMEOUT'] ?? 30);
$debugMode = filter_var($_ENV['DEBUG_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Type validation
function validateConfig() {
    $required = ['DATABASE_URL', 'API_KEY', 'APP_SECRET'];
    foreach ($required as $var) {
        if (empty($_ENV[$var])) {
            throw new Exception("Required environment variable {$var} is not set");
        }
    }
}
?>
```

### Security Considerations

1. **Shared Database**: All apps share the same PostgreSQL instance
2. **Table Isolation**: Use prefixes to prevent conflicts
3. **Access Control**: Implement application-level security
4. **Data Privacy**: Apps can technically access each other's data

### Example: Complete App Structure

```
/app/code/apps/my-blog/
├── index.php              # Main entry point
├── config/
│   └── database.php       # App-specific DB helper
├── includes/
│   ├── functions.php      # App functions
│   └── auth.php          # Authentication
├── templates/
│   ├── header.php
│   └── footer.php
└── assets/
    ├── css/
    └── js/
```

#### index.php
```php
<?php
require_once '/app/code/public/config/database.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Initialize app database
$db = getDbConnection();
initializeBlogDatabase($db);

// Get posts
$posts = getBlogPosts($db);

include __DIR__ . '/templates/header.php';
?>

<h1>My Blog</h1>
<div class="posts">
    <?php foreach ($posts as $post): ?>
        <article>
            <h2><?= htmlspecialchars($post['title']) ?></h2>
            <p><?= htmlspecialchars($post['content']) ?></p>
            <small>Posted: <?= $post['created_at'] ?></small>
        </article>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
```

## File Storage and Uploads

### Available Storage Locations

#### App Directory (Read/Write)
```bash
/app/code/apps/[your-app]/        # Your app's main directory
├── uploads/                      # Recommended for user uploads
├── cache/                        # Temporary files and cache
├── logs/                         # Application logs
└── tmp/                          # Temporary processing files
```

#### Shared Data Directory (Read/Write)
```bash
/app/data/                        # Persistent shared storage
├── uploads/                      # Global uploads (use with caution)
└── shared/                       # Shared resources between apps
```

### File Upload Example

```php
<?php
function handleFileUpload($inputName, $appDirectory) {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload failed');
    }
    
    $uploadDir = "/app/code/apps/{$appDirectory}/uploads/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $file = $_FILES[$inputName];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('File type not allowed');
    }
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large');
    }
    
    $filename = uniqid() . '_' . basename($file['name']);
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $destination,
            'url' => "/apps/{$appDirectory}/uploads/{$filename}"
        ];
    }
    
    throw new Exception('Failed to move uploaded file');
}

// Usage
try {
    $result = handleFileUpload('avatar', $_ENV['APP_DIRECTORY']);
    echo "File uploaded successfully: " . $result['url'];
} catch (Exception $e) {
    echo "Upload failed: " . $e->getMessage();
}
?>
```

### Image Processing Example

```php
<?php
function resizeImage($source, $destination, $maxWidth = 800, $maxHeight = 600) {
    list($width, $height, $type) = getimagesize($source);
    
    // Calculate new dimensions
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($source);
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($source);
            break;
        default:
            throw new Exception('Unsupported image type');
    }
    
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save processed image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($newImage, $destination, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($newImage, $destination);
            break;
        case IMAGETYPE_GIF:
            imagegif($newImage, $destination);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($newImage);
    
    return true;
}
?>
```

## Available PHP Features

### Installed Extensions
```bash
# Core Extensions
- pdo_pgsql (PostgreSQL support)
- curl (HTTP requests)
- mbstring (Multibyte strings)
- xml (XML processing)
- zip (Archive handling)
- gd (Image processing)
- json (JSON handling - built-in)

# Available Functions
- file_get_contents() / file_put_contents()
- curl_* functions for API calls
- imagecreatej*() functions for image manipulation
- zip_* functions for archive creation
```

### HTTP Client Example

```php
<?php
function makeHttpRequest($url, $data = null, $headers = []) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge([
            'User-Agent: PHP-Git-App-Manager/1.0',
            'Accept: application/json',
        ], $headers),
    ]);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            curl_getopt($ch, CURLOPT_HTTPHEADER),
            ['Content-Type: application/json']
        ));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("HTTP request failed: {$error}");
    }
    
    return [
        'status_code' => $httpCode,
        'body' => $response,
        'data' => json_decode($response, true)
    ];
}

// Usage - API Integration Example
try {
    $result = makeHttpRequest('https://api.example.com/data', [
        'key' => 'value'
    ]);
    
    if ($result['status_code'] === 200) {
        $data = $result['data'];
        // Process API response
    }
} catch (Exception $e) {
    error_log("API call failed: " . $e->getMessage());
}
?>
```

## Session and Caching

### Session Management

```php
<?php
// Sessions are automatically configured
session_start();

// App-specific session namespace
$sessionKey = 'app_' . $_ENV['APP_ID'];
if (!isset($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [];
}

function setAppSession($key, $value) {
    $sessionKey = 'app_' . $_ENV['APP_ID'];
    $_SESSION[$sessionKey][$key] = $value;
}

function getAppSession($key, $default = null) {
    $sessionKey = 'app_' . $_ENV['APP_ID'];
    return $_SESSION[$sessionKey][$key] ?? $default;
}
?>
```

### File-based Caching

```php
<?php
class SimpleCache {
    private $cacheDir;
    
    public function __construct($appDirectory) {
        $this->cacheDir = "/app/code/apps/{$appDirectory}/cache/";
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    public function set($key, $value, $ttl = 3600) {
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        return file_put_contents(
            $this->cacheDir . md5($key) . '.cache',
            serialize($data),
            LOCK_EX
        ) !== false;
    }
    
    public function get($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        if (!file_exists($file)) {
            return null;
        }
        
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function delete($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        return file_exists($file) ? unlink($file) : true;
    }
}

// Usage
$cache = new SimpleCache($_ENV['APP_DIRECTORY']);
$cache->set('user_data', $userData, 3600); // Cache for 1 hour
$userData = $cache->get('user_data');
?>
```

## Logging and Debugging

### Application Logging

```php
<?php
function appLog($message, $level = 'INFO', $context = []) {
    $logDir = "/app/code/apps/" . $_ENV['APP_DIRECTORY'] . "/logs/";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $appName = $_ENV['APP_NAME'];
    
    $logEntry = "[{$timestamp}] {$appName}.{$level}: {$message}";
    if (!empty($context)) {
        $logEntry .= ' ' . json_encode($context);
    }
    $logEntry .= PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Usage
appLog('User logged in', 'INFO', ['user_id' => 123]);
appLog('Database query failed', 'ERROR', ['query' => $sql, 'error' => $e->getMessage()]);
?>
```

### Error Handling

```php
<?php
// Custom error handler for your app
function appErrorHandler($errno, $errstr, $errfile, $errline) {
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_NOTICE => 'NOTICE',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE'
    ];
    
    $type = $errorTypes[$errno] ?? 'UNKNOWN';
    appLog("PHP {$type}: {$errstr}", 'ERROR', [
        'file' => $errfile,
        'line' => $errline
    ]);
    
    // Don't execute PHP's internal error handler
    return true;
}

set_error_handler('appErrorHandler');

// Exception handler
function appExceptionHandler($exception) {
    appLog('Uncaught exception: ' . $exception->getMessage(), 'ERROR', [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
}

set_exception_handler('appExceptionHandler');
?>
```

## Security Considerations

### Debug and Sensitive Information Protection

**CRITICAL**: Never expose debug endpoints or sensitive information publicly:

```php
<?php
// Always protect debug/admin endpoints with authentication
function requireAdminAuth() {
    session_start();
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        // Log unauthorized access attempt
        error_log("Unauthorized access attempt to " . $_SERVER['REQUEST_URI'] . " from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        
        // Return 404 to hide existence
        http_response_code(404);
        die('404 Not Found');
    }
}

// Use in any debug/admin files
requireAdminAuth();
?>
```

**Environment Variable Security**:
```php
<?php
// Never expose sensitive env vars in debug output
function sanitizeEnvVar($key, $value) {
    $sensitiveKeywords = ['PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'URL', 'PRIVATE'];
    
    foreach ($sensitiveKeywords as $keyword) {
        if (stripos($key, $keyword) !== false) {
            return '[REDACTED]';
        }
    }
    
    return $value;
}

// Safe debug output
foreach ($_ENV as $key => $value) {
    echo $key . ': ' . sanitizeEnvVar($key, $value) . "\n";
}
?>
```

Following [phpBB security practices](https://www.phpbb.com/community/viewtopic.php?t=2160312), disable debug output in production:

```php
<?php
// In production, disable all debug output
if (getenv('CLOUDRON_ENVIRONMENT') === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}
?>
```

### Input Validation

```php
<?php
function validateInput($data, $rules) {
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
                }
            }
            
            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                $errors[$field] = "Maximum length is {$rule['max_length']} characters";
            }
        }
    }
    
    return empty($errors) ? null : $errors;
}

// Usage
$rules = [
    'email' => ['required' => true, 'type' => 'email'],
    'name' => ['required' => true, 'max_length' => 100],
    'age' => ['type' => 'int']
];

$errors = validateInput($_POST, $rules);
if ($errors) {
    // Handle validation errors
}
?>
```

### CSRF Protection

```php
<?php
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// In forms
echo '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';

// In processing
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('CSRF token validation failed');
}
?>
```

## Performance Optimization

### Database Query Optimization

```php
<?php
// Use prepared statements with proper indexing
function getPostsByCategory($db, $categoryId, $limit = 10, $offset = 0) {
    // Ensure you have an index on category_id and created_at
    $stmt = $db->prepare("
        SELECT p.*, u.username 
        FROM myapp_posts p 
        JOIN myapp_users u ON p.user_id = u.id 
        WHERE p.category_id = ? 
        ORDER BY p.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$categoryId, $limit, $offset]);
    return $stmt->fetchAll();
}

// Connection pooling - reuse database connections
class DatabasePool {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            self::$connection = getDbConnection();
        }
        return self::$connection;
    }
}
?>
```

### Memory Management

```php
<?php
// Process large datasets in chunks
function processLargeDataset($db, $chunkSize = 1000) {
    $offset = 0;
    
    do {
        $stmt = $db->prepare("SELECT * FROM large_table LIMIT ? OFFSET ?");
        $stmt->execute([$chunkSize, $offset]);
        $records = $stmt->fetchAll();
        
        foreach ($records as $record) {
            // Process each record
            processRecord($record);
        }
        
        $offset += $chunkSize;
        
        // Free memory
        unset($records);
        gc_collect_cycles();
        
    } while (count($records) === $chunkSize);
}
?>
```

## Deployment Best Practices

### Directory Structure

```
/app/code/apps/your-app/
├── index.php                    # Main entry point
├── config/
│   ├── app.php                 # App configuration
│   └── database.php            # Database helpers
├── includes/
│   ├── functions.php           # Helper functions
│   ├── auth.php               # Authentication
│   └── validation.php         # Input validation
├── templates/
│   ├── layout/
│   │   ├── header.php
│   │   └── footer.php
│   └── pages/
│       ├── home.php
│       └── about.php
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── uploads/                    # User uploads
├── cache/                      # Cache files
├── logs/                       # Application logs
├── vendor/                     # Composer dependencies
├── composer.json              # Dependencies
└── .htaccess                  # Apache configuration
```

### .htaccess Configuration

```apache
RewriteEngine On
RewriteBase /apps/your-app/

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# Cache static assets
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/pdf "access plus 1 month"
    ExpiresByType text/javascript "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>

# Protect sensitive files
<Files ~ "^\.">
    Order allow,deny
    Deny from all
</Files>

<Files ~ "(composer\.(json|lock)|\.env|\.log)$">
    Order allow,deny
    Deny from all
</Files>

# Pretty URLs
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

This comprehensive setup allows deployed PHP applications to leverage the full power of the Cloudron environment while maintaining proper security, performance, and isolation standards. The system follows [modern cloud deployment practices](https://www.sitepoint.com/ultimate-guide-deploying-php-apps-cloud/) including proper separation of concerns, scalable architecture, and security best practices. 