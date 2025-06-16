<?php
/**
 * Nextcloud AI Chatbot - Admin Panel
 * 
 * Main entry point for the admin interface using Slim Framework
 */

declare(strict_types=1);

// Load the main bootstrap
require_once __DIR__ . '/../src/bootstrap.php';

use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter\ResponseEmitter;
use Slim\Middleware\ErrorMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use EducBot\Helpers\Logger;
use EducBot\Models\Settings;
use EducBot\Models\RoomConfig;
use EducBot\Models\Conversation;
use EducBot\Services\ApiClient;
use EducBot\Services\VectorStore;

// Initialize session
session_start();

// Create Slim app
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(
    getenv('CLOUDRON_ENVIRONMENT') !== 'production',  // Display error details in dev
    true,  // Log errors
    true   // Log error details
);

// Initialize Twig
$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($loader, [
    'cache' => false, // Disable cache for development
    'debug' => getenv('CLOUDRON_ENVIRONMENT') !== 'production'
]);

// Add CSRF protection middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    
    // Add security headers
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('X-XSS-Protection', '1; mode=block')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// Authentication middleware
$authMiddleware = function (Request $request, $handler) {
    $path = $request->getUri()->getPath();
    
    // Allow login page and login action
    if (in_array($path, ['/login', '/admin/login', '/admin/auth']) || 
        strpos($path, '/assets/') !== false) {
        return $handler->handle($request);
    }
    
    // Check if user is authenticated
    if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(
            '<script>window.location.href="/admin/login";</script>'
        );
        return $response->withStatus(302);
    }
    
    return $handler->handle($request);
};

$app->add($authMiddleware);

// CSRF token generator
function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Helper function to render templates
function renderTemplate(Response $response, string $template, array $data = []): Response {
    global $twig;
    
    // Add global template variables
    $data['csrf_token'] = generateCSRFToken();
    $data['app_name'] = 'EDUC AI Chatbot';
    $data['current_year'] = date('Y');
    
    try {
        $html = $twig->render($template, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    } catch (Exception $e) {
        Logger::error('Template rendering failed', [
            'template' => $template,
            'error' => $e->getMessage()
        ]);
        
        $response->getBody()->write('Template rendering error: ' . $e->getMessage());
        return $response->withStatus(500);
    }
}

// Routes

// Login page
$app->get('/admin/login', function (Request $request, Response $response) {
    // If already authenticated, redirect to dashboard
    if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
        return $response->withHeader('Location', '/admin/')->withStatus(302);
    }
    
    return renderTemplate($response, 'login.twig');
});

// Login authentication
$app->post('/admin/auth', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $password = $data['password'] ?? '';
    
    if (empty($password)) {
        return renderTemplate($response, 'login.twig', [
            'error' => 'Password is required'
        ]);
    }
    
    try {
        // Check admin credentials from database
        $db = getDbConnection();
        $stmt = $db->query('SELECT password_hash FROM bot_admin ORDER BY id DESC LIMIT 1');
        $admin = $stmt->fetch();
        
        if (!$admin) {
            // No admin user exists, create one with the provided password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO bot_admin (password_hash) VALUES (?)');
            $stmt->execute([$passwordHash]);
            
            $_SESSION['admin_authenticated'] = true;
            Logger::info('Admin user created and authenticated');
            
            return $response->withHeader('Location', '/admin/')->withStatus(302);
        }
        
        if (password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_authenticated'] = true;
            
            // Update last login time
            $stmt = $db->prepare('UPDATE bot_admin SET last_login = CURRENT_TIMESTAMP WHERE id = (SELECT id FROM bot_admin ORDER BY id DESC LIMIT 1)');
            $stmt->execute();
            
            Logger::info('Admin user authenticated');
            return $response->withHeader('Location', '/admin/')->withStatus(302);
        }
        
        return renderTemplate($response, 'login.twig', [
            'error' => 'Invalid password'
        ]);
        
    } catch (Exception $e) {
        Logger::error('Authentication failed', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'login.twig', [
            'error' => 'Authentication error occurred'
        ]);
    }
});

// Logout
$app->get('/admin/logout', function (Request $request, Response $response) {
    session_destroy();
    return $response->withHeader('Location', '/admin/login')->withStatus(302);
});

// Dashboard
$app->get('/admin/', function (Request $request, Response $response) {
    try {
        $settings = new Settings();
        $stats = Conversation::getGlobalStats();
        $vectorStore = new VectorStore();
        $embeddingStats = $vectorStore->getStats();
        
        // Get recent activity
        $recentMessages = Conversation::getRecentMessages(10);
        $activeRooms = Conversation::getMostActiveRooms(5);
        
        return renderTemplate($response, 'dashboard.twig', [
            'stats' => $stats,
            'embedding_stats' => $embeddingStats,
            'recent_messages' => $recentMessages,
            'active_rooms' => $activeRooms,
            'bot_mention' => $settings->getBotMention()
        ]);
        
    } catch (Exception $e) {
        Logger::error('Dashboard error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load dashboard: ' . $e->getMessage()
        ]);
    }
});

// Settings page
$app->get('/admin/settings', function (Request $request, Response $response) {
    try {
        $settings = new Settings();
        $apiClient = new ApiClient(getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT'));
        
        // Test API connection
        $apiTest = $apiClient->testConnection();
        
        // Get available models
        $models = [];
        if ($apiTest['success']) {
            try {
                $models = $apiClient->getModels();
            } catch (Exception $e) {
                Logger::warning('Failed to fetch models', ['error' => $e->getMessage()]);
            }
        }
        
        return renderTemplate($response, 'settings.twig', [
            'settings' => $settings->getAll(),
            'api_test' => $apiTest,
            'models' => $models
        ]);
        
    } catch (Exception $e) {
        Logger::error('Settings page error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load settings: ' . $e->getMessage()
        ]);
    }
});

// Update settings
$app->post('/admin/settings', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    
    if (!validateCSRFToken($data['csrf_token'] ?? '')) {
        return renderTemplate($response, 'error.twig', [
            'error' => 'CSRF token validation failed'
        ]);
    }
    
    try {
        $settings = new Settings();
        
        // Validate and update settings
        $errors = $settings->validate($data);
        if (!empty($errors)) {
            return renderTemplate($response, 'settings.twig', [
                'settings' => $settings->getAll(),
                'errors' => $errors,
                'form_data' => $data
            ]);
        }
        
        // Remove CSRF token from data
        unset($data['csrf_token']);
        
        $settings->updateMultiple($data);
        
        return renderTemplate($response, 'settings.twig', [
            'settings' => $settings->getAll(),
            'success' => 'Settings updated successfully'
        ]);
        
    } catch (Exception $e) {
        Logger::error('Settings update failed', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'settings.twig', [
            'settings' => (new Settings())->getAll(),
            'error' => 'Failed to update settings: ' . $e->getMessage()
        ]);
    }
});

// Documents page (for RAG management)
$app->get('/admin/documents', function (Request $request, Response $response) {
    try {
        $db = getDbConnection();
        $stmt = $db->query('
            SELECT bd.*, COUNT(be.id) as embedding_count 
            FROM bot_docs bd 
            LEFT JOIN bot_embeddings be ON bd.id = be.doc_id 
            GROUP BY bd.id 
            ORDER BY bd.created_at DESC
        ');
        $documents = $stmt->fetchAll();
        
        return renderTemplate($response, 'documents.twig', [
            'documents' => $documents
        ]);
        
    } catch (Exception $e) {
        Logger::error('Documents page error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load documents: ' . $e->getMessage()
        ]);
    }
});

// Conversations page
$app->get('/admin/conversations', function (Request $request, Response $response) {
    try {
        $roomConfigs = RoomConfig::getAll(50);
        $globalStats = Conversation::getGlobalStats();
        
        return renderTemplate($response, 'conversations.twig', [
            'room_configs' => $roomConfigs,
            'global_stats' => $globalStats
        ]);
        
    } catch (Exception $e) {
        Logger::error('Conversations page error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load conversations: ' . $e->getMessage()
        ]);
    }
});

// Logs page
$app->get('/admin/logs', function (Request $request, Response $response) {
    try {
        $level = $request->getQueryParams()['level'] ?? null;
        $logs = Logger::getRecentLogs(100, $level);
        
        return renderTemplate($response, 'logs.twig', [
            'logs' => $logs,
            'current_level' => $level
        ]);
        
    } catch (Exception $e) {
        Logger::error('Logs page error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load logs: ' . $e->getMessage()
        ]);
    }
});

// API Status page
$app->get('/admin/api-status', function (Request $request, Response $response) {
    try {
        $apiClient = new ApiClient(getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT'));
        
        // Test various API endpoints
        $tests = [
            'connection' => $apiClient->testConnection(),
        ];
        
        // Get usage statistics
        $usageStats = ApiClient::getUsageStats(7);
        
        return renderTemplate($response, 'api-status.twig', [
            'tests' => $tests,
            'usage_stats' => $usageStats
        ]);
        
    } catch (Exception $e) {
        Logger::error('API status page error', ['error' => $e->getMessage()]);
        return renderTemplate($response, 'error.twig', [
            'error' => 'Failed to load API status: ' . $e->getMessage()
        ]);
    }
});

// Handle static assets
$app->get('/admin/assets/{file:.+}', function (Request $request, Response $response, array $args) {
    $file = $args['file'];
    $filePath = __DIR__ . '/assets/' . $file;
    
    if (!file_exists($filePath) || !is_file($filePath)) {
        return $response->withStatus(404);
    }
    
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml'
    ];
    
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    $response->getBody()->write(file_get_contents($filePath));
    return $response->withHeader('Content-Type', $mimeType);
});

// Redirect root to admin
$app->get('/admin', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/admin/')->withStatus(302);
});

// Run the application
try {
    $serverRequestCreator = ServerRequestCreatorFactory::create();
    $request = $serverRequestCreator->createServerRequestFromGlobals();
    
    // Set base path for admin routes
    $uri = $request->getUri();
    $path = $uri->getPath();
    
    // If the path doesn't start with /admin, prepend it
    if (strpos($path, '/admin') !== 0 && $path !== '/') {
        $path = '/admin' . $path;
        $uri = $uri->withPath($path);
        $request = $request->withUri($uri);
    }
    
    $response = $app->handle($request);
    
    $responseEmitter = new ResponseEmitter();
    $responseEmitter->emit($response);
    
} catch (Exception $e) {
    Logger::error('Admin panel fatal error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    http_response_code(500);
    echo "Internal Server Error";
} 