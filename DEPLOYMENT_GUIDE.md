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

**🛡️ Security Note:** The files `custom-env.php` and `auto-include.php` are intentionally hidden from web browsers by `.htaccess` rules for security. They exist on the filesystem and work correctly - they're just protected from direct web access to prevent exposure of environment variables.

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

## 🔧 Advanced Deployment Features

### Automatic Dependency Management

The deployment system automatically handles various dependency managers:

#### Composer (PHP Dependencies)
```bash
# The system automatically detects composer.json and:
1. Tries multiple composer paths (/usr/local/bin/composer, /usr/bin/composer, composer)
2. Runs: composer install --no-dev --optimize-autoloader --no-interaction
3. Falls back to downloading composer.phar if not found
4. Verifies vendor/ directory creation
```

**Troubleshooting Composer Issues:**
- If you see "Failed opening required 'vendor/autoload.php'", the deployment logs will show exactly what happened
- Check deployment logs via the "📋 View Logs" button in the admin panel
- The system tries multiple fallback methods for maximum compatibility

#### npm (Node.js Dependencies)
```bash
# For applications with package.json:
npm install --production
```

#### Automatic Index Generation

For webhook/API applications without an `index.php`, the system creates a beautiful landing page:

```php
<?php
// Auto-generated features:
- Application information display
- File browser (with security filtering)
- Quick access to admin/, connect.php, src/, README.md
- Environment information
- Direct links to admin panels
?>
```

### 🔒 Security Enhancements

#### Automatic .htaccess Protection

Every deployed application gets comprehensive security protection:

```apache
# Generated by PHP Git App Manager
RewriteEngine On

# Block access to sensitive directories
RedirectMatch 404 /\.git
RedirectMatch 404 /vendor
RedirectMatch 404 /node_modules
RedirectMatch 404 /\.env
RedirectMatch 404 /deployment\.log
RedirectMatch 404 /auto-include\.php
RedirectMatch 404 /custom-env\.php
RedirectMatch 404 /composer\.(json|lock)
RedirectMatch 404 /package(-lock)?\.json

# URL Rewriting
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### Landing Page Security

Auto-generated landing pages hide sensitive files and directories:

```php
<?php
// Hidden from public view:
$hiddenDirs = [
    '.git',           // Git repository data
    'vendor',         // Composer dependencies  
    'node_modules',   // npm dependencies
    '.env',           // Environment files
    '.htaccess',      // Apache configuration
    'deployment.log', // Deployment logs
    'auto-include.php', // Auto-generated includes
    'custom-env.php'  // Custom environment variables
];

// Only safe files are shown in file browser
?>
```

### 📊 Dashboard Statistics

The admin dashboard shows accurate, real-time statistics:

```php
<?php
// Dashboard metrics:
- Total Applications: All registered apps
- Deployed Apps: Only successfully deployed and active apps  
- Recent Deployments: Completed deployments in last 24 hours
- Vector Embeddings: Total embeddings stored (if table exists)
?>
```

### 🔄 Improved Deployment Process

#### Enhanced Logging with ULTRA DEEP Analysis
- **Comprehensive deployment logs** to `apps/{app-directory}/deployment.log`
- **System environment analysis**: PHP version, memory limits, current user, permissions
- **Pre-deployment verification**: Directory creation, writability checks, ownership analysis
- **Git clone monitoring**: Repository validation, branch verification, file count tracking
- **File operation tracking**: Individual file moves with success/failure status
- **Permission management**: Granular ownership and permission setting with verification
- **Composer debugging**: Multiple path detection, HOME environment setup, vendor verification
- **Custom environment files**: Creation verification, file size tracking, permission analysis

#### Deployment Status Tracking
- **Pending**: Deployment queued
- **Running**: Currently deploying (shows progress)
- **Completed**: Successfully deployed and active
- **Failed**: Deployment failed (with detailed error logs)

#### Auto-Include Intelligence
- **Smart detection**: Automatically detects if `index.php` already includes auto-include functionality
- **Auto-generated pages**: Landing pages have auto-include built-in by design
- **User files**: Safely adds auto-include to existing user-created index.php files
- **Detailed analysis**: Logs file size, auto-generation status, and include verification
- **No duplication**: Prevents duplicate auto-include statements in files

#### Background Process Management
```bash
# The deployment system:
1. Creates deployment record with 'pending' status
2. Starts background PHP script with proper logging
3. Updates status to 'running' with progress indicators
4. Handles errors gracefully with detailed logging
5. Marks as 'completed' or 'failed' with full logs
```

## 🚨 Troubleshooting Guide

### Common Issues and Solutions

#### 1. "vendor/autoload.php not found" Error 500

**Symptoms:**
```
PHP Fatal error: Failed opening required 'vendor/autoload.php'
```

**Solutions:**
```bash
1. Check deployment logs via "📋 View Logs" button
2. Verify composer.json exists in repository root
3. Redeploy the application (system will retry Composer install)
4. If persistent, check if Composer is available in the container
```

**Prevention:**
- Ensure your repository has a valid `composer.json`
- The deployment system now handles this automatically

#### 2. Dashboard Shows "0 Deployed Apps"

**Symptoms:**
- Apps are deployed but dashboard shows 0
- Applications page shows deployed apps correctly

**Solution:**
```sql
-- The dashboard now correctly counts only active, deployed apps:
SELECT COUNT(*) FROM applications 
WHERE deployed = true AND status = 'active'
```

**Fixed in latest version:** Dashboard statistics now use correct SQL queries.

#### 3. Sensitive Directories Exposed

**Symptoms:**
- Users can access `/apps/yourapp/.git/`
- Vendor dependencies visible in browser

**Solution:**
```apache
# Now automatically protected via .htaccess:
- /.git/ returns 404
- /vendor/ returns 404  
- /node_modules/ returns 404
- Configuration files blocked
```

**Fixed in latest version:** All deployments get automatic `.htaccess` protection.

#### 4. App Stuck at "Pending" Status

**Symptoms:**
```
Deployment Status: Pending (for extended time)
No deployment logs visible
```

**Troubleshooting Steps:**
```bash
1. Click "📋 View Logs" to see detailed error messages
2. Check if PHP binary is available
3. Verify database connection in debug panel
4. Look for specific error messages in logs
```

**Common Causes:**
- PHP path issues (now auto-detected)
- Database connection problems
- Git clone failures
- Permission issues

#### 5. PostgreSQL Boolean Syntax Errors

**Symptoms:**
```
SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input syntax for type boolean: ""
Failed to add custom env var: invalid input syntax for type boolean
```

**Root Cause:** HTML checkboxes send empty strings when unchecked, but PostgreSQL boolean fields require explicit 'true'/'false' strings.

**Fixed in latest version:**
```php
// Enhanced boolean conversion for PostgreSQL compatibility
function convertToPgBoolean($value) {
    if ($value === true || $value === 'true' || $value === 1 || $value === '1' || $value === 'yes' || $value === 'on') {
        return 'true';
    } else {
        return 'false';
    }
}

// Use explicit parameter binding with PostgreSQL-compatible strings
$stmt->bindParam(4, $pgBoolValue, PDO::PARAM_STR);
```

#### 6. "Custom Environment Files Missing" (False Alarm)

**Symptoms:**
```
Files custom-env.php and auto-include.php not visible in file browser
Cannot access /apps/yourapp/custom-env.php directly
```

**This is NOT an error!** These files are intentionally hidden by `.htaccess` for security:

```apache
# Security protection in auto-generated .htaccess
RedirectMatch 404 /auto-include\.php
RedirectMatch 404 /custom-env\.php
```

**Verification:** Use the test endpoint at `/apps/yourapp/test-env.php` to verify files exist and work correctly.

**Fixed in latest version:** Enhanced deployment logs now clearly indicate files are created and protected.

#### 7. Git Clone Failures

**Symptoms:**
```
❌ CRITICAL: Git clone failed - temp directory not created
Repository appears to be empty or branch has no content
```

**Enhanced diagnostics now show:**
```
🔍 Possible causes:
   - Invalid repository URL
   - Branch 'dev-pascal' doesn't exist
   - Network connectivity issues
   - Permission denied on repository
   - Repository is private and requires authentication
```

**Solutions:**
- Verify repository URL is public and accessible
- Check branch name exists in repository
- Test network connectivity from server
- For private repos, configure authentication

#### 8. File Moving Failures

**Symptoms:**
```
❌ CRITICAL: No files were moved successfully
Some files failed to move, but continuing deployment
```

**Enhanced logging shows individual file operations:**
```
🔄 Moving: composer.json... ✅ SUCCESS
🔄 Moving: src/... ❌ FAILED
📊 Move operation summary:
   ✅ Successfully moved: 15 files/directories
   ❌ Failed to move: 2 items
```

**Solutions:**
- Check disk space on target directory
- Verify file system permissions
- Review specific failed files in logs

#### 9. Permission Issues

**Enhanced permission management now includes:**
```
📊 Current app directory permissions: 0755
👤 Current owner: 33, Group: 33
👥 Setting ownership to www-data:www-data...
🔐 Setting directory permissions (755) and file permissions (644)...
✅ Final app directory permissions: 0755
```

### Debug Tools

#### Built-in Debug Panel
Access via `/server-admin/?action=debug` to check:
- PHP version and extensions
- Database connection and pgvector support
- File permissions and disk space
- System environment variables

#### View Deployment Logs
- Click "📋 View Logs" next to any application
- Shows complete deployment history without redeploying
- Includes Composer output, error messages, and status updates

#### Health Check Endpoint
```bash
curl https://your-domain.com/health.php
# Returns: JSON with system status
```

## 🎯 Best Practices for Deployed Applications

### Repository Structure
```
your-app/
├── index.php              # Main entry point (or auto-generated)
├── composer.json          # PHP dependencies (auto-detected)
├── package.json           # Node.js dependencies (auto-detected)
├── admin/                 # Admin panel (auto-linked in landing page)
├── src/                   # Source code (auto-linked in landing page)
├── connect.php            # Webhook endpoint (auto-linked)
├── README.md              # Documentation (auto-linked)
├── .env.example           # Environment template
└── .gitignore             # Version control exclusions
```

### Environment Variables
```php
<?php
// Available in all deployed apps:
$_ENV['APP_NAME']          // Application name
$_ENV['APP_ID']            // Unique application ID  
$_ENV['APP_DIRECTORY']     // Directory name

// Cloudron environment:
$_ENV['CLOUDRON_POSTGRESQL_URL']    // Database connection
$_ENV['CLOUDRON_APP_DOMAIN']        // App domain
$_ENV['CLOUDRON_ENVIRONMENT']       // production/development

// Custom variables (via admin panel):
$_ENV['YOUR_CUSTOM_VAR']   // Set via Settings → Environment Variables
?>
```

### Security Considerations
```php
<?php
// Never expose in public endpoints:
- Database credentials (auto-hidden in logs)
- Environment variable values (sanitized in debug output)  
- Git repository data (blocked by .htaccess)
- Vendor dependencies (blocked by .htaccess)
- Configuration files (blocked by .htaccess)

// Debug endpoints should always require authentication:
if (getenv('CLOUDRON_ENVIRONMENT') === 'production') {
    // Hide all debug information
    error_reporting(0);
    ini_set('display_errors', 0);
}
?>
```

This comprehensive setup allows deployed PHP applications to leverage the full power of the Cloudron environment while maintaining proper security, performance, and isolation standards. The system follows [modern cloud deployment practices](https://www.sitepoint.com/ultimate-guide-deploying-php-apps-cloud/) including proper separation of concerns, scalable architecture, and security best practices. 