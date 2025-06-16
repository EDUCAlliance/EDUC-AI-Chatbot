<?php

declare(strict_types=1);

// The deployment system generates this file to load all environment variables.
if (file_exists(__DIR__ . '/../auto-include.php')) {
    require_once __DIR__ . '/../auto-include.php';
}

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use NextcloudBot\Helpers\Session;
use NextcloudBot\Helpers\Csrf;

require __DIR__ . '/../src/bootstrap.php';

// --- App Initialization ---
$app = AppFactory::create();
$app->setBasePath('/admin');

// --- Twig Templates ---
$twig = Twig::create(__DIR__ . '/templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

// --- Database & Logger ---
$db = NextcloudBot\getDbConnection();
$logger = new \NextcloudBot\Helpers\Logger();

// --- Auto-DB-Schema Installation ---
try {
    // Check if a key table exists. If not, create schema.
    $db->query("SELECT 1 FROM bot_admin LIMIT 1");
} catch (\PDOException $e) {
    // Error 42P01 in PostgreSQL means "undefined table"
    if ($e->getCode() === '42P01') {
        try {
            $sql = file_get_contents(__DIR__ . '/../database.sql');
            $db->exec($sql);
        } catch (\Exception $initError) {
            // If schema creation fails, stop execution.
            http_response_code(500);
            die("Database schema initialization failed: " . $initError->getMessage());
        }
    } else {
        // For other DB errors, re-throw the exception.
        throw $e;
    }
}

// --- Middleware for Auth ---
$authMiddleware = function (Request $request, $handler) {
    Session::start();
    if (!Session::has('admin_logged_in')) {
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
    return $handler->handle($request);
};

// --- First Run Check ---
$stmt = $db->query("SELECT id FROM bot_admin LIMIT 1");
$adminExists = $stmt->fetchColumn();

if (!$adminExists) {
    // --- First Run Setup Route ---
    $app->map(['GET', 'POST'], '/setup', function (Request $request, Response $response) use ($db, $twig) {
        if ($request->getMethod() === 'POST') {
            $password = $request->getParsedBody()['password'] ?? '';
            $passwordConfirm = $request->getParsedBody()['password_confirm'] ?? '';

            if ($password && $password === $passwordConfirm) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO bot_admin (password_hash) VALUES (?)");
                $stmt->execute([$hash]);
                
                // Seed default settings
                $db->exec("INSERT INTO bot_settings (id, mention_name, onboarding_group_questions, onboarding_dm_questions) VALUES (1, '@educai', '[]', '[]') ON CONFLICT(id) DO NOTHING");

                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }
        }
        return $twig->render($response, 'setup.twig');
    })->setName('setup');

    // Redirect all other requests to setup
    $app->get('/{routes:.*}', function (Request $request, Response $response) {
         return $response->withHeader('Location', '/admin/setup')->withStatus(302);
    });

            } else {
    // --- Standard Routes ---
    $app->map(['GET', 'POST'], '/login', function (Request $request, Response $response) use ($db, $twig) {
        if ($request->getMethod() === 'POST') {
            $password = $request->getParsedBody()['password'] ?? '';
            $stmt = $db->query("SELECT password_hash FROM bot_admin LIMIT 1");
            $hash = $stmt->fetchColumn();

            if (password_verify($password, $hash)) {
                Session::start();
                Session::regenerate();
                Session::set('admin_logged_in', true);
                return $response->withHeader('Location', '/admin/')->withStatus(302);
            }
        }
        return $twig->render($response, 'login.twig');
    })->setName('login');

    $app->get('/logout', function (Request $request, Response $response) {
        Session::destroy();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    })->setName('logout');

    $app->get('/', function (Request $request, Response $response) use ($twig, $db) {
        $stats = [
            'docs' => $db->query("SELECT COUNT(*) FROM bot_docs")->fetchColumn(),
            'embeddings' => $db->query("SELECT COUNT(*) FROM bot_embeddings")->fetchColumn(),
            'conversations' => $db->query("SELECT COUNT(*) FROM bot_conversations")->fetchColumn(),
            'rooms' => $db->query("SELECT COUNT(*) FROM bot_room_config")->fetchColumn(),
        ];
        return $twig->render($response, 'dashboard.twig', ['stats' => $stats]);
    })->setName('dashboard')->add($authMiddleware);
    
    // Add other protected admin routes here...
}


$app->run(); 