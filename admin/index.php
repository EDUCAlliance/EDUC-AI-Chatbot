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
use NextcloudBot\Services\VectorStore;

require __DIR__ . '/../src/bootstrap.php';

// --- App Initialization ---
$app = AppFactory::create();
$basePath = '/apps/' . (getenv('APP_DIRECTORY') ?: 'educ-ai-chatbot') . '/admin';
$app->setBasePath($basePath);

// --- Twig Templates ---
$twig = Twig::create(__DIR__ . '/templates', ['cache' => false]);

// Make session data available to all Twig templates
Session::start();
$twig->getEnvironment()->addGlobal('session', $_SESSION['nextcloud_bot_session'] ?? []);

// The `url_for` function in Twig needs the RouteParser, which TwigMiddleware adds to the container
$app->add(TwigMiddleware::create($app, $twig));

// --- Database, Services & Schema ---
$db = NextcloudBot\getDbConnection();
$logger = new \NextcloudBot\Helpers\Logger();
$apiClient = new ApiClient(getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1', $logger);
$vectorStore = new VectorStore($db);
$embeddingService = new EmbeddingService($apiClient, $vectorStore, $logger, $db);

try {
    $db->query("SELECT 1 FROM bot_admin LIMIT 1");
} catch (\PDOException $e) {
    if ($e->getCode() === '42P01') {
        $db->exec(file_get_contents(__DIR__ . '/../database.sql'));
    } else {
        throw $e;
    }
}

// Add new columns if they don't exist (for schema migration)
try {
    $db->exec("ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS mention_name TEXT DEFAULT '@educai'");
    $db->exec("ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct'");
    $db->exec("ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_top_k INTEGER DEFAULT 3");
    $db->exec("ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_chunk_size INTEGER DEFAULT 250");
    $db->exec("ALTER TABLE bot_settings ADD COLUMN IF NOT EXISTS rag_chunk_overlap INTEGER DEFAULT 25");
} catch (\PDOException $e) {
    // Schema migration might fail on some PostgreSQL versions, but that's okay if columns already exist
    error_log("Schema migration warning: " . $e->getMessage());
}

// Initialize default bot settings if not exists
$settingsExist = $db->query("SELECT COUNT(*) FROM bot_settings WHERE id = 1")->fetchColumn();
if ($settingsExist == 0) {
    $stmt = $db->prepare("INSERT INTO bot_settings (id, mention_name) VALUES (1, ?)");
    $stmt->execute(['@educai']);
} else {
    // Ensure mention_name is set for existing records
    $db->exec("UPDATE bot_settings SET mention_name = '@educai' WHERE id = 1 AND mention_name IS NULL");
}

// --- Middleware & Initial State Check ---
$adminExists = (bool) $db->query("SELECT id FROM bot_admin LIMIT 1")->fetchColumn();

$authMiddleware = function (Request $request, $handler) use ($app) {
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
    return $twig->render($response, 'documents.twig', ['docs' => $docs, 'currentPage' => 'documents']);
})->setName('documents')->add($authMiddleware);

$app->post('/documents/upload', function (Request $request, Response $response) use ($db, $embeddingService, $app) {
    $uploadedFile = $request->getUploadedFiles()['document'];
    if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
        $uploadDir = APP_ROOT . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        $basename = basename($uploadedFile->getClientFilename());
        $targetPath = $uploadDir . '/' . $basename;
        $uploadedFile->moveTo($targetPath);
        $content = file_get_contents($targetPath);
        $checksum = hash('sha256', $content);
        $stmt = $db->prepare("SELECT id FROM bot_docs WHERE checksum = ?");
        $stmt->execute([$checksum]);
        if ($stmt->fetch()) {
            unlink($targetPath);
        } else {
            $stmt = $db->prepare("INSERT INTO bot_docs (filename, path, checksum) VALUES (?, ?, ?)");
            $stmt->execute([$basename, $targetPath, $checksum]);
            $docId = $db->lastInsertId();
            $embeddingService->generateAndStoreEmbeddings((int)$docId, $content);
        }
    }
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents'))->withStatus(302);
})->setName('documents-upload')->add($authMiddleware);

$app->get('/models', function (Request $request, Response $response) use ($twig, $db, $apiClient) {
    $apiModels = $apiClient->getModels();
    $settings = $db->query("SELECT default_model FROM bot_settings WHERE id = 1")->fetch();
    return $twig->render($response, 'models.twig', ['models' => $apiModels['data'] ?? [], 'current_model' => $settings['default_model'] ?? '', 'currentPage' => 'models']);
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
    return $twig->render($response, 'prompt.twig', ['system_prompt' => $settings['system_prompt'] ?? '', 'currentPage' => 'prompt']);
})->setName('prompt')->add($authMiddleware);

$app->map(['GET', 'POST'], '/onboarding', function (Request $request, Response $response) use ($twig, $db, $app) {
    if ($request->getMethod() === 'POST') {
        $botMention = trim($request->getParsedBody()['bot_mention'] ?? '@educai');
        $groupQuestions = $request->getParsedBody()['group_questions'] ?? '';
        $dmQuestions = $request->getParsedBody()['dm_questions'] ?? '';
        
        // Ensure mention starts with @
        if (!str_starts_with($botMention, '@')) {
            $botMention = '@' . $botMention;
        }
        
        $groupJson = json_encode(array_filter(array_map('trim', explode("\n", $groupQuestions))));
        $dmJson = json_encode(array_filter(array_map('trim', explode("\n", $dmQuestions))));
        
        // Update bot mention and onboarding questions
        $stmt = $db->prepare("UPDATE bot_settings SET mention_name = ?, onboarding_group_questions = ?, onboarding_dm_questions = ? WHERE id = 1");
        $stmt->execute([$botMention, $groupJson, $dmJson]);
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('onboarding'))->withStatus(302);
    }
    
    // Get current settings
    $settings = $db->query("SELECT mention_name, onboarding_group_questions, onboarding_dm_questions FROM bot_settings WHERE id = 1")->fetch();
    
    return $twig->render($response, 'onboarding.twig', [
        'bot_mention' => $settings['mention_name'] ?? '@educai',
        'group_questions' => implode("\n", json_decode($settings['onboarding_group_questions'] ?? '[]', true)), 
        'dm_questions' => implode("\n", json_decode($settings['onboarding_dm_questions'] ?? '[]', true)), 
        'currentPage' => 'onboarding'
    ]);
})->setName('onboarding')->add($authMiddleware);

$app->map(['GET', 'POST'], '/rag-settings', function (Request $request, Response $response) use ($twig, $db, $app, $apiClient) {
    if ($request->getMethod() === 'POST') {
        $body = $request->getParsedBody();
        $stmt = $db->prepare("UPDATE bot_settings SET embedding_model = ?, rag_top_k = ?, rag_chunk_size = ?, rag_chunk_overlap = ? WHERE id = 1");
        $stmt->execute([$body['embedding_model'] ?? 'e5-mistral-7b-instruct', (int)($body['rag_top_k'] ?? 3), (int)($body['rag_chunk_size'] ?? 250), (int)($body['rag_chunk_overlap'] ?? 25)]);
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('rag-settings'))->withStatus(302);
    }
    $models = $apiClient->getModels()['data'] ?? [];
    $embeddingModels = array_filter($models, fn($m) => strpos($m['id'], 'e5') !== false || strpos($m['id'], 'embed') !== false);
    $settings = $db->query("SELECT * FROM bot_settings WHERE id = 1")->fetch();
    return $twig->render($response, 'rag_settings.twig', ['settings' => $settings, 'embeddingModels' => $embeddingModels, 'currentPage' => 'rag-settings']);
})->setName('rag-settings')->add($authMiddleware);

$app->run(); 