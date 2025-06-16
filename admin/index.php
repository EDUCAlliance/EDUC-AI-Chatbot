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
use NextcloudBot\Services\ApiClient;
use NextcloudBot\Services\EmbeddingService;

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
$apiClient = new ApiClient(getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1', new \NextcloudBot\Helpers\Logger());
$embeddingService = new EmbeddingService($apiClient, new \NextcloudBot\Services\VectorStore($db), new \NextcloudBot\Helpers\Logger());

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
    return $twig->render($response, 'dashboard.twig', ['stats' => $stats, 'currentPage' => 'dashboard']);
})->setName('dashboard')->add($authMiddleware);

$app->get('/documents', function (Request $request, Response $response) use ($twig, $db) {
    $docs = $db->query("SELECT id, filename, created_at FROM bot_docs ORDER BY created_at DESC")->fetchAll();
    return $twig->render($response, 'documents.twig', [
        'docs' => $docs,
        'currentPage' => 'documents',
    ]);
})->setName('documents')->add($authMiddleware);

$app->post('/documents/upload', function (Request $request, Response $response) use ($db, $embeddingService, $app) {
    $uploadedFile = $request->getUploadedFiles()['document'];
    if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
        $filename = $uploadedFile->getClientFilename();
        $uploadPath = APP_ROOT . '/uploads/' . $filename;
        $uploadedFile->moveTo($uploadPath);

        $content = file_get_contents($uploadPath);
        $checksum = hash('sha256', $content);

        // Check for duplicates
        $stmt = $db->prepare("SELECT id FROM bot_docs WHERE checksum = ?");
        $stmt->execute([$checksum]);
        if ($stmt->fetch()) {
            // Handle duplicate file
            unlink($uploadPath);
        } else {
            $stmt = $db->prepare("INSERT INTO bot_docs (filename, path, checksum) VALUES (?, ?, ?)");
            $stmt->execute([$filename, $uploadPath, $checksum]);
            $docId = $db->lastInsertId();
            $embeddingService->generateAndStoreEmbeddings((int)$docId, $content);
        }
    }
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents'))->withStatus(302);
})->setName('documents-upload')->add($authMiddleware);

$app->get('/models', function (Request $request, Response $response) use ($twig, $db, $apiClient) {
    $apiModels = $apiClient->getModels();
    $settings = $db->query("SELECT default_model FROM bot_settings WHERE id = 1")->fetch();
    return $twig->render($response, 'models.twig', [
        'models' => $apiModels['data'] ?? [],
        'current_model' => $settings['default_model'] ?? '',
        'currentPage' => 'models',
    ]);
})->setName('models')->add($authMiddleware);

$app->post('/models', function (Request $request, Response $response) use ($db, $app) {
    $model = $request->getParsedBody()['model'] ?? '';
    $stmt = $db->prepare("UPDATE bot_settings SET default_model = ? WHERE id = 1");
    $stmt->execute([$model]);
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('models'))->withStatus(302);
})->setName('models-update')->add($authMiddleware);

$app->map(['GET', 'POST'], '/prompt', function (Request $request, Response $response) use ($twig, $db, $app) {
    if ($request->getMethod() === 'POST') {
        $prompt = $request->getParsedBody()['system_prompt'] ?? '';
        $stmt = $db->prepare("UPDATE bot_settings SET system_prompt = ? WHERE id = 1");
        $stmt->execute([$prompt]);
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('prompt'))->withStatus(302);
    }
    $settings = $db->query("SELECT system_prompt FROM bot_settings WHERE id = 1")->fetch();
    return $twig->render($response, 'prompt.twig', [
        'system_prompt' => $settings['system_prompt'] ?? '',
        'currentPage' => 'prompt',
    ]);
})->setName('prompt')->add($authMiddleware);

$app->map(['GET', 'POST'], '/onboarding', function (Request $request, Response $response) use ($twig, $db, $app) {
    if ($request->getMethod() === 'POST') {
        $groupQuestions = $request->getParsedBody()['group_questions'] ?? '';
        $dmQuestions = $request->getParsedBody()['dm_questions'] ?? '';

        $groupJson = json_encode(array_filter(array_map('trim', explode("\n", $groupQuestions))));
        $dmJson = json_encode(array_filter(array_map('trim', explode("\n", $dmQuestions))));

        $stmt = $db->prepare("UPDATE bot_settings SET onboarding_group_questions = ?, onboarding_dm_questions = ? WHERE id = 1");
        $stmt->execute([$groupJson, $dmJson]);
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('onboarding'))->withStatus(302);
    }

    $settings = $db->query("SELECT onboarding_group_questions, onboarding_dm_questions FROM bot_settings WHERE id = 1")->fetch();
    return $twig->render($response, 'onboarding.twig', [
        'group_questions' => implode("\n", json_decode($settings['onboarding_group_questions'] ?? '[]', true)),
        'dm_questions' => implode("\n", json_decode($settings['onboarding_dm_questions'] ?? '[]', true)),
        'currentPage' => 'onboarding',
    ]);
})->setName('onboarding')->add($authMiddleware);

$app->run(); 