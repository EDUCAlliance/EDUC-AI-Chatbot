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
    
    // Add processing status column to bot_docs
    $db->exec("ALTER TABLE bot_docs ADD COLUMN IF NOT EXISTS processing_status TEXT DEFAULT 'completed'");
    
    // Create progress tracking table
    $db->exec("CREATE TABLE IF NOT EXISTS bot_processing_progress (
        id SERIAL PRIMARY KEY,
        doc_id INTEGER REFERENCES bot_docs(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT 'pending',
        progress INTEGER DEFAULT 0,
        current_chunk INTEGER DEFAULT 0,
        total_chunks INTEGER DEFAULT 0,
        error_message TEXT,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP,
        UNIQUE(doc_id)
    )");
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

// Fix bot_room_config table structure if needed
try {
    // Check and fix room_type -> is_group column
    $columnCheck = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'bot_room_config' AND column_name = 'room_type'")->fetchColumn();
    if ($columnCheck) {
        $db->exec("ALTER TABLE bot_room_config RENAME COLUMN room_type TO is_group_temp");
        $db->exec("ALTER TABLE bot_room_config ADD COLUMN is_group BOOLEAN DEFAULT true");
        $db->exec("UPDATE bot_room_config SET is_group = (is_group_temp = 'group')");
        $db->exec("ALTER TABLE bot_room_config DROP COLUMN is_group_temp");
    }
    
    // Check and fix onboarding_state -> onboarding_done column  
    $stateCheck = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'bot_room_config' AND column_name = 'onboarding_state'")->fetchColumn();
    if ($stateCheck) {
        $db->exec("ALTER TABLE bot_room_config ADD COLUMN IF NOT EXISTS onboarding_done BOOLEAN DEFAULT false");
        $db->exec("UPDATE bot_room_config SET onboarding_done = (onboarding_state = 'completed')");
        $db->exec("ALTER TABLE bot_room_config DROP COLUMN onboarding_state");
    }
    
    // Ensure mention_mode column exists
    $db->exec("ALTER TABLE bot_room_config ADD COLUMN IF NOT EXISTS mention_mode TEXT DEFAULT 'on_mention'");
} catch (\PDOException $e) {
    error_log("Room config schema migration warning: " . $e->getMessage());
}

// Ensure vector dimension is correct (4096)
try {
    $result = $db->query("
        SELECT atttypmod
        FROM pg_attribute
        WHERE attrelid = 'bot_embeddings'::regclass
          AND attname = 'embedding'
    ")->fetchColumn();
    
    // The dimension is stored in atttypmod. For vectors, it seems to be the dimension.
    // We check if it's not 4096.
    if ($result && (int)$result !== 4096) {
        $logger->warning('Incorrect vector dimension detected. Attempting to migrate...');
        // First, delete any existing, incompatible embeddings.
        $db->exec("DELETE FROM bot_embeddings");
        // Now, alter the column type. This is the critical fix.
        $db->exec("ALTER TABLE bot_embeddings ALTER COLUMN embedding TYPE vector(4096)");
        $logger->info('Successfully migrated bot_embeddings table to 4096 dimensions.');
    }
} catch (\PDOException $e) {
    // This might fail if the pg_vector extension isn't fully available or on different DB systems.
    // We log it but don't crash the admin panel.
    $logger->error('Could not verify/migrate vector dimensions.', ['error' => $e->getMessage()]);
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

// Async upload endpoint
$app->post('/documents/upload-async', function (Request $request, Response $response) use ($db, $logger) {
    try {
        $uploadedFile = $request->getUploadedFiles()['document'];
        
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \Exception('File upload error: ' . $uploadedFile->getError());
        }
        
        $uploadDir = APP_ROOT . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        
        $basename = basename($uploadedFile->getClientFilename());
        $targetPath = $uploadDir . '/' . $basename;
        $uploadedFile->moveTo($targetPath);
        
        $content = file_get_contents($targetPath);
        $checksum = hash('sha256', $content);
        
        // Check for duplicates
        $stmt = $db->prepare("SELECT id FROM bot_docs WHERE checksum = ?");
        $stmt->execute([$checksum]);
        if ($existingDoc = $stmt->fetch()) {
            unlink($targetPath);
            throw new \Exception('Document already exists');
        }
        
        // Insert document record
        $stmt = $db->prepare("INSERT INTO bot_docs (filename, path, checksum, processing_status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$basename, $targetPath, $checksum]);
        $docId = $db->lastInsertId();
        
        // Initialize progress tracking
        $stmt = $db->prepare("INSERT INTO bot_processing_progress (doc_id, status, progress, started_at) VALUES (?, 'started', 0, NOW())");
        $stmt->execute([$docId]);
        
        $logger->info('Document uploaded, starting background processing', ['doc_id' => $docId, 'filename' => $basename]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'doc_id' => $docId,
            'filename' => $basename
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $logger->error('Upload failed', ['error' => $e->getMessage()]);
        
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
})->setName('documents-upload-async')->add($authMiddleware);

// Server-Sent Events endpoint for embedding progress
$app->get('/embedding-progress/{docId}', function (Request $request, Response $response, array $args) use ($db, $embeddingService, $logger) {
    $docId = (int)$args['docId'];
    
    // Set SSE headers
    $response = $response
        ->withHeader('Content-Type', 'text/event-stream')
        ->withHeader('Cache-Control', 'no-cache')
        ->withHeader('Connection', 'keep-alive')
        ->withHeader('Access-Control-Allow-Origin', '*');
    
    // Get document info
    $stmt = $db->prepare("SELECT filename, path FROM bot_docs WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    
    if (!$doc) {
        $response->getBody()->write("data: " . json_encode(['error' => 'Document not found']) . "\n\n");
        return $response;
    }
    
    try {
        // Start processing in background
        $embeddingService->generateAndStoreEmbeddingsAsync($docId, file_get_contents($doc['path']));
        
        // Stream progress updates
        while (true) {
            $stmt = $db->prepare("SELECT status, progress, current_chunk, total_chunks, error_message, completed_at FROM bot_processing_progress WHERE doc_id = ?");
            $stmt->execute([$docId]);
            $progress = $stmt->fetch();
            
            if (!$progress) {
                break;
            }
            
            $data = [
                'progress' => (int)$progress['progress'],
                'status' => $progress['status'],
                'completed' => !empty($progress['completed_at']),
                'error' => $progress['error_message']
            ];
            
            if ($progress['total_chunks'] > 0) {
                $data['status'] = sprintf('Processing chunk %d of %d...', $progress['current_chunk'], $progress['total_chunks']);
            }
            
            if ($data['completed']) {
                // Get final stats
                $statsStmt = $db->prepare("SELECT COUNT(*) as embeddings FROM bot_embeddings WHERE doc_id = ?");
                $statsStmt->execute([$docId]);
                $stats = $statsStmt->fetch();
                
                $data['stats'] = [
                    'chunks' => $progress['total_chunks'],
                    'embeddings' => $stats['embeddings']
                ];
                
                $data['document'] = [
                    'id' => $docId,
                    'filename' => $doc['filename'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                // Update document status
                $db->prepare("UPDATE bot_docs SET processing_status = 'completed' WHERE id = ?")->execute([$docId]);
            }
            
            if ($data['error']) {
                // Update document status
                $db->prepare("UPDATE bot_docs SET processing_status = 'failed' WHERE id = ?")->execute([$docId]);
            }
            
            $response->getBody()->write("data: " . json_encode($data) . "\n\n");
            
            if ($data['completed'] || $data['error']) {
                break;
            }
            
            if (connection_aborted()) {
                break;
            }
            
            sleep(1);
        }
        
    } catch (\Exception $e) {
        $logger->error('Embedding processing failed', ['doc_id' => $docId, 'error' => $e->getMessage()]);
        
        $data = [
            'progress' => 0,
            'status' => 'Processing failed',
            'completed' => false,
            'error' => $e->getMessage()
        ];
        
        $response->getBody()->write("data: " . json_encode($data) . "\n\n");
        
        // Update progress table
        $db->prepare("UPDATE bot_processing_progress SET status = 'failed', error_message = ? WHERE doc_id = ?")
           ->execute([$e->getMessage(), $docId]);
        $db->prepare("UPDATE bot_docs SET processing_status = 'failed' WHERE id = ?")->execute([$docId]);
    }
    
    return $response;
})->setName('embedding-progress')->add($authMiddleware);

// Fallback polling endpoint
$app->get('/embedding-status/{docId}', function (Request $request, Response $response, array $args) use ($db) {
    $docId = (int)$args['docId'];
    
    $stmt = $db->prepare("SELECT status, progress, current_chunk, total_chunks, error_message, completed_at FROM bot_processing_progress WHERE doc_id = ?");
    $stmt->execute([$docId]);
    $progress = $stmt->fetch();
    
    if (!$progress) {
        $response->getBody()->write(json_encode(['error' => 'Progress not found']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    $data = [
        'progress' => (int)$progress['progress'],
        'status' => $progress['status'],
        'completed' => !empty($progress['completed_at']),
        'error' => $progress['error_message']
    ];
    
    if ($progress['total_chunks'] > 0) {
        $data['status'] = sprintf('Processing chunk %d of %d...', $progress['current_chunk'], $progress['total_chunks']);
    }
    
    if ($data['completed']) {
        $statsStmt = $db->prepare("SELECT COUNT(*) as embeddings FROM bot_embeddings WHERE doc_id = ?");
        $statsStmt->execute([$docId]);
        $stats = $statsStmt->fetch();
        
        $data['stats'] = [
            'chunks' => $progress['total_chunks'],
            'embeddings' => $stats['embeddings']
        ];
        
        $docStmt = $db->prepare("SELECT filename FROM bot_docs WHERE id = ?");
        $docStmt->execute([$docId]);
        $doc = $docStmt->fetch();
        
        $data['document'] = [
            'id' => $docId,
            'filename' => $doc['filename'],
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
})->setName('embedding-status')->add($authMiddleware);

// Cancel processing endpoint
$app->post('/cancel-processing/{docId}', function (Request $request, Response $response, array $args) use ($db, $logger) {
    $docId = (int)$args['docId'];
    
    try {
        // Mark processing as cancelled
        $stmt = $db->prepare("UPDATE bot_processing_progress SET status = 'cancelled', error_message = 'Cancelled by user' WHERE doc_id = ? AND completed_at IS NULL");
        $stmt->execute([$docId]);
        
        // Mark document as failed
        $stmt = $db->prepare("UPDATE bot_docs SET processing_status = 'cancelled' WHERE id = ?");
        $stmt->execute([$docId]);
        
        $logger->info('Processing cancelled by user', ['doc_id' => $docId]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $logger->error('Failed to cancel processing', ['doc_id' => $docId, 'error' => $e->getMessage()]);
        
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
})->setName('cancel-processing')->add($authMiddleware);

$app->post('/documents/delete', function (Request $request, Response $response) use ($db, $app, $logger) {
    $contentType = $request->getHeaderLine('Content-Type');
    
    if (strpos($contentType, 'application/json') !== false) {
        // Handle JSON request (from JavaScript)
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);
        $docId = $data['doc_id'] ?? null;
        
        try {
            if (!$docId) {
                throw new \Exception('Document ID is required');
            }
            
            $db->beginTransaction();

            // Find the document to get its path for deletion
            $stmt = $db->prepare("SELECT path FROM bot_docs WHERE id = ?");
            $stmt->execute([(int)$docId]);
            $doc = $stmt->fetch();

            if ($doc && !empty($doc['path'])) {
                if (file_exists($doc['path'])) {
                    unlink($doc['path']);
                    $logger->info('Deleted document file', ['path' => $doc['path']]);
                } else {
                    $logger->warning('Document file not found, but proceeding with DB deletion', ['path' => $doc['path']]);
                }
            }

            // Delete progress tracking
            $stmt = $db->prepare("DELETE FROM bot_processing_progress WHERE doc_id = ?");
            $stmt->execute([(int)$docId]);
            
            // Delete embeddings associated with the document
            $stmt = $db->prepare("DELETE FROM bot_embeddings WHERE doc_id = ?");
            $stmt->execute([(int)$docId]);
            $logger->info('Deleted embeddings for document', ['doc_id' => $docId]);

            // Delete the document record itself
            $stmt = $db->prepare("DELETE FROM bot_docs WHERE id = ?");
            $stmt->execute([(int)$docId]);
            $logger->info('Deleted document record from database', ['doc_id' => $docId]);

            $db->commit();
            
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $db->rollBack();
            $logger->error('Failed to delete document', ['error' => $e->getMessage(), 'doc_id' => $docId]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    } else {
        // Handle form request (legacy)
        $docId = $request->getParsedBody()['doc_id'] ?? null;

        if ($docId) {
            try {
                $db->beginTransaction();

                // Find the document to get its path for deletion
                $stmt = $db->prepare("SELECT path FROM bot_docs WHERE id = ?");
                $stmt->execute([(int)$docId]);
                $doc = $stmt->fetch();

                if ($doc && !empty($doc['path'])) {
                    if (file_exists($doc['path'])) {
                        unlink($doc['path']);
                        $logger->info('Deleted document file', ['path' => $doc['path']]);
                    } else {
                        $logger->warning('Document file not found, but proceeding with DB deletion', ['path' => $doc['path']]);
                    }
                }

                // Delete progress tracking
                $stmt = $db->prepare("DELETE FROM bot_processing_progress WHERE doc_id = ?");
                $stmt->execute([(int)$docId]);
                
                // Delete embeddings associated with the document
                $stmt = $db->prepare("DELETE FROM bot_embeddings WHERE doc_id = ?");
                $stmt->execute([(int)$docId]);
                $logger->info('Deleted embeddings for document', ['doc_id' => $docId]);

                // Delete the document record itself
                $stmt = $db->prepare("DELETE FROM bot_docs WHERE id = ?");
                $stmt->execute([(int)$docId]);
                $logger->info('Deleted document record from database', ['doc_id' => $docId]);

                $db->commit();
            } catch (\PDOException $e) {
                $db->rollBack();
                $logger->error('Failed to delete document', ['error' => $e->getMessage(), 'doc_id' => $docId]);
            }
        }

        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents'))->withStatus(302);
    }
})->setName('documents-delete')->add($authMiddleware);

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

// Helper function to read log entries
function getLogEntries(int $limit = 100): array {
    $logDir = APP_ROOT . '/logs';
    $logFile = $logDir . '/' . date('Y-m-d') . '.log';
    
    if (!file_exists($logFile)) {
        return [];
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    
    // Get last N lines
    $lines = array_slice($lines, -$limit);
    $logs = [];
    
    foreach (array_reverse($lines) as $line) {
        // Parse log format: [2024-06-16 14:25:15] [INFO] Message {context}
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\] (.+)$/', $line, $matches)) {
            $timestamp = $matches[1];
            $level = $matches[2];
            $messageAndContext = $matches[3];
            
            // Try to separate message from JSON context
            $message = $messageAndContext;
            $context = null;
            
            // Look for JSON context at the end
            if (preg_match('/^(.+?) (\{.+\})$/', $messageAndContext, $contextMatches)) {
                $message = $contextMatches[1];
                $contextJson = $contextMatches[2];
                
                // Try to decode and pretty-print JSON
                $contextData = json_decode($contextJson, true);
                if ($contextData !== null) {
                    $context = json_encode($contextData, JSON_PRETTY_PRINT);
                } else {
                    $context = $contextJson;
                }
            }
            
            $logs[] = [
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'fullLine' => $line
            ];
        } else {
            // If line doesn't match expected format, include it as-is
            $logs[] = [
                'timestamp' => '',
                'level' => 'UNKNOWN',
                'message' => $line,
                'context' => null,
                'fullLine' => $line
            ];
        }
    }
    
    return $logs;
}

$app->get('/logs', function (Request $request, Response $response) use ($twig) {
    $params = $request->getQueryParams();
    
    // Handle download request
    if (isset($params['download'])) {
        $logs = getLogEntries(1000);
        $content = implode("\n", array_map(fn($log) => $log['fullLine'], $logs));
        $response = $response->withHeader('Content-Type', 'text/plain')
                           ->withHeader('Content-Disposition', 'attachment; filename="bot-logs-' . date('Y-m-d-H-i-s') . '.log"');
        $response->getBody()->write($content);
        return $response;
    }
    
    // Get log entries
    $logs = getLogEntries(200);
    
    // Calculate log statistics
    $logStats = [
        'total' => count($logs),
        'errors' => count(array_filter($logs, fn($log) => $log['level'] === 'ERROR')),
        'warnings' => count(array_filter($logs, fn($log) => $log['level'] === 'WARNING')),
        'info' => count(array_filter($logs, fn($log) => $log['level'] === 'INFO'))
    ];
    
    return $twig->render($response, 'logs.twig', [
        'logs' => $logs, 
        'logStats' => $logStats,
        'currentPage' => 'logs'
    ]);
})->setName('logs')->add($authMiddleware);

$app->run(); 