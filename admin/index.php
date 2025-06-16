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

require __DIR__ . '/../src/bootstrap.php';

// --- App Initialization ---
$app = AppFactory::create();
$basePath = '/apps/' . (getenv('APP_DIRECTORY') ?: 'educ-ai-chatbot') . '/admin';
$app->setBasePath($basePath);

// --- Twig Templates ---
$twig = Twig::create(__DIR__ . '/templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

// --- Database & Schema ---
$db = NextcloudBot\getDbConnection();
try {
    $db->query("SELECT 1 FROM bot_admin LIMIT 1");
} catch (\PDOException $e) {
    if ($e->getCode() === '42P01') {
        try {
            $sql = file_get_contents(__DIR__ . '/../database.sql');
            $db->exec($sql);
        } catch (\Exception $initError) {
            http_response_code(500);
            die("Database schema initialization failed: " . $initError->getMessage());
        }
    } else {
        throw $e;
    }
}

// --- Middleware & Initial State Check ---
$adminExists = (bool) $db->query("SELECT id FROM bot_admin LIMIT 1")->fetchColumn();

$authMiddleware = function (Request $request, $handler) use ($app) {
    Session::start();
    if (!Session::has('admin_logged_in')) {
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('login'))->withStatus(302);
    }
    return $handler->handle($request);
};

// --- Route Definitions ---

$app->map(['GET', 'POST'], '/setup', function (Request $request, Response $response) use ($db, $twig, $app, $adminExists) {
    if ($adminExists) {
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('login'))->withStatus(302);
    }
    if ($request->getMethod() === 'POST') {
        $password = $request->getParsedBody()['password'] ?? '';
        $passwordConfirm = $request->getParsedBody()['password_confirm'] ?? '';
        if ($password && $password === $passwordConfirm) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO bot_admin (password_hash) VALUES (?)")->execute([$hash]);
            $db->exec("INSERT INTO bot_settings (id) VALUES (1) ON CONFLICT(id) DO NOTHING");
            return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('login'))->withStatus(302);
        }
    }
    return $twig->render($response, 'setup.twig');
})->setName('setup');

$app->map(['GET', 'POST'], '/login', function (Request $request, Response $response) use ($db, $twig, $app, $adminExists) {
    if (!$adminExists) {
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('setup'))->withStatus(302);
    }
    if ($request->getMethod() === 'POST') {
        $password = $request->getParsedBody()['password'] ?? '';
        $hash = $db->query("SELECT password_hash FROM bot_admin LIMIT 1")->fetchColumn();
        if (password_verify($password, $hash)) {
            Session::start();
            Session::regenerate();
            Session::set('admin_logged_in', true);
            return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('dashboard'))->withStatus(302);
        }
    }
    return $twig->render($response, 'login.twig');
})->setName('login');

$app->get('/logout', function (Request $request, Response $response) use ($app) {
    Session::destroy();
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('login'))->withStatus(302);
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

$app->run(); 