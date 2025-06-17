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

// Make app directory available to all templates for proper asset paths
$twig->getEnvironment()->addGlobal('app_directory', getenv('APP_DIRECTORY') ?: 'educ-ai-chatbot');

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

// --- Multi-Bot Migration ---
function performMultiBotMigration($db, $logger) {
    try {
        // Check if migration is needed
        $botsTableExists = false;
        try {
            $db->query("SELECT 1 FROM bots LIMIT 1");
            $botsTableExists = true;
        } catch (\PDOException $e) {
            // Table doesn't exist, proceed with migration
            $logger->info('Starting multi-bot migration: bots table not found');
        }
        
        if (!$botsTableExists) {
            $logger->info('Creating bots table and performing migration');
            
            // Step 1: Create bots table
            $db->exec("
                CREATE TABLE IF NOT EXISTS bots (
                  id SERIAL PRIMARY KEY,
                  bot_name TEXT NOT NULL,
                  mention_name TEXT UNIQUE NOT NULL,
                  default_model TEXT DEFAULT 'meta-llama-3.1-8b-instruct',
                  system_prompt TEXT,
                  onboarding_group_questions JSONB,
                  onboarding_dm_questions JSONB,
                  embedding_model TEXT DEFAULT 'e5-mistral-7b-instruct',
                  rag_top_k INTEGER DEFAULT 3,
                  rag_chunk_size INTEGER DEFAULT 250,
                  rag_chunk_overlap INTEGER DEFAULT 25,
                  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                  updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Step 2: Add bot_id columns to existing tables
            $db->exec("ALTER TABLE bot_docs ADD COLUMN IF NOT EXISTS bot_id INTEGER");
            $db->exec("ALTER TABLE bot_room_config ADD COLUMN IF NOT EXISTS bot_id INTEGER");
            $db->exec("ALTER TABLE bot_conversations ADD COLUMN IF NOT EXISTS bot_id INTEGER");
            
            // Step 3: Migrate data from bot_settings
            $settingsStmt = $db->query("SELECT * FROM bot_settings WHERE id = 1");
            $settings = $settingsStmt->fetch();
            
            if ($settings) {
                // Create default bot from existing settings
                $stmt = $db->prepare("
                    INSERT INTO bots (bot_name, mention_name, default_model, system_prompt, 
                                    onboarding_group_questions, onboarding_dm_questions, 
                                    embedding_model, rag_top_k, rag_chunk_size, rag_chunk_overlap) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    'Default Bot',
                    $settings['mention_name'] ?: '@educai',
                    $settings['default_model'] ?: 'meta-llama-3.1-8b-instruct',
                    $settings['system_prompt'],
                    $settings['onboarding_group_questions'],
                    $settings['onboarding_dm_questions'],
                    $settings['embedding_model'] ?: 'e5-mistral-7b-instruct',
                    $settings['rag_top_k'] ?: 3,
                    $settings['rag_chunk_size'] ?: 250,
                    $settings['rag_chunk_overlap'] ?: 25
                ]);
                
                $defaultBotId = $db->lastInsertId();
                $logger->info('Created default bot with ID: ' . $defaultBotId);
            } else {
                // Create a minimal default bot if no settings exist
                $stmt = $db->prepare("
                    INSERT INTO bots (bot_name, mention_name) VALUES (?, ?)
                ");
                $stmt->execute(['Default Bot', '@educai']);
                $defaultBotId = $db->lastInsertId();
                $logger->info('Created minimal default bot with ID: ' . $defaultBotId);
            }
            
            // Step 4: Update existing records with bot_id
            $db->prepare("UPDATE bot_docs SET bot_id = ? WHERE bot_id IS NULL")->execute([$defaultBotId]);
            $db->prepare("UPDATE bot_room_config SET bot_id = ? WHERE bot_id IS NULL")->execute([$defaultBotId]);
            $db->prepare("UPDATE bot_conversations SET bot_id = ? WHERE bot_id IS NULL")->execute([$defaultBotId]);
            
            // Step 5: Fix unique constraint on bot_docs to be per-bot
            try {
                // Drop the old unique constraint on checksum
                $db->exec("ALTER TABLE bot_docs DROP CONSTRAINT IF EXISTS bot_docs_checksum_key");
                // Add new unique constraint on (checksum, bot_id)
                $db->exec("ALTER TABLE bot_docs ADD CONSTRAINT bot_docs_checksum_bot_id_unique UNIQUE (checksum, bot_id)");
                $logger->info('Updated bot_docs unique constraint to be per-bot');
            } catch (\PDOException $e) {
                $logger->warning('Could not update bot_docs unique constraint: ' . $e->getMessage());
            }
            
            // Step 6: Add foreign key constraints
            try {
                $db->exec("ALTER TABLE bot_docs ADD CONSTRAINT fk_bot_docs_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE");
            } catch (\PDOException $e) {
                $logger->warning('Could not add foreign key constraint for bot_docs: ' . $e->getMessage());
            }
            
            try {
                $db->exec("ALTER TABLE bot_room_config ADD CONSTRAINT fk_bot_room_config_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE");
            } catch (\PDOException $e) {
                $logger->warning('Could not add foreign key constraint for bot_room_config: ' . $e->getMessage());
            }
            
            try {
                $db->exec("ALTER TABLE bot_conversations ADD CONSTRAINT fk_bot_conversations_bot_id FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE");
            } catch (\PDOException $e) {
                $logger->warning('Could not add foreign key constraint for bot_conversations: ' . $e->getMessage());
            }
            
            $logger->info('Multi-bot migration completed successfully');
        }
    } catch (\PDOException $e) {
        $logger->error('Multi-bot migration failed: ' . $e->getMessage());
        throw $e;
    }
}

// Perform the migration
performMultiBotMigration($db, $logger);

// Fix unique constraint on bot_docs if it still exists in the old format
try {
    // Check if the old constraint exists
    $constraintExists = $db->query("
        SELECT 1 FROM information_schema.table_constraints 
        WHERE table_name = 'bot_docs' 
        AND constraint_name = 'bot_docs_checksum_key'
    ")->fetchColumn();
    
    if ($constraintExists) {
        $logger->info('Fixing bot_docs unique constraint to be per-bot');
        $db->exec("ALTER TABLE bot_docs DROP CONSTRAINT bot_docs_checksum_key");
        $db->exec("ALTER TABLE bot_docs ADD CONSTRAINT bot_docs_checksum_bot_id_unique UNIQUE (checksum, bot_id)");
        $logger->info('Successfully updated bot_docs unique constraint');
    }
} catch (\PDOException $e) {
    $logger->warning('Could not fix bot_docs unique constraint: ' . $e->getMessage());
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
        'bots' => $db->query("SELECT COUNT(*) FROM bots")->fetchColumn(),
        'docs' => $db->query("SELECT COUNT(*) FROM bot_docs")->fetchColumn(),
        'embeddings' => $db->query("SELECT COUNT(*) FROM bot_embeddings")->fetchColumn(),
        'conversations' => $db->query("SELECT COUNT(*) FROM bot_conversations")->fetchColumn(),
        'rooms' => $db->query("SELECT COUNT(*) FROM bot_room_config")->fetchColumn(),
    ];
    return $twig->render($response, 'dashboard.twig', ['stats' => $stats, 'currentPage' => 'dashboard']);
})->setName('dashboard')->add($authMiddleware);

// Bot management routes
$app->get('/bots', function (Request $request, Response $response) use ($twig, $db) {
    $bots = $db->query("SELECT * FROM bots ORDER BY created_at DESC")->fetchAll();
    return $twig->render($response, 'bots.twig', ['bots' => $bots, 'currentPage' => 'bots']);
})->setName('bots')->add($authMiddleware);

$app->map(['GET', 'POST'], '/bots/create', function (Request $request, Response $response) use ($twig, $db, $app) {
    if ($request->getMethod() === 'POST') {
        $data = $request->getParsedBody();
        $botName = trim($data['bot_name'] ?? '');
        $mentionName = trim($data['mention_name'] ?? '');
        
        // Ensure mention starts with @
        if (!str_starts_with($mentionName, '@')) {
            $mentionName = '@' . $mentionName;
        }
        
        if ($botName && $mentionName) {
            try {
                $stmt = $db->prepare("INSERT INTO bots (bot_name, mention_name) VALUES (?, ?)");
                $stmt->execute([$botName, $mentionName]);
                return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
            } catch (\PDOException $e) {
                $error = 'Failed to create bot: ' . $e->getMessage();
                return $twig->render($response, 'bot_create.twig', ['error' => $error, 'currentPage' => 'bots']);
            }
        } else {
            $error = 'Bot name and mention name are required';
            return $twig->render($response, 'bot_create.twig', ['error' => $error, 'currentPage' => 'bots']);
        }
    }
    return $twig->render($response, 'bot_create.twig', ['currentPage' => 'bots']);
})->setName('bot-create')->add($authMiddleware);

$app->get('/bots/{id}/settings', function (Request $request, Response $response, array $args) use ($twig, $db, $apiClient) {
    $botId = (int)$args['id'];
    $bot = $db->prepare("SELECT * FROM bots WHERE id = ?");
    $bot->execute([$botId]);
    $botData = $bot->fetch();
    
    if (!$botData) {
        return $response->withStatus(404);
    }
    
    // Decode JSON fields for easier template handling
    $botData['onboarding_group_questions_array'] = json_decode($botData['onboarding_group_questions'] ?? '[]', true) ?: [];
    $botData['onboarding_dm_questions_array'] = json_decode($botData['onboarding_dm_questions'] ?? '[]', true) ?: [];
    
    $apiModels = $apiClient->getModels();
    $models = $apiModels['data'] ?? [];
    $embeddingModels = array_filter($models, fn($m) => strpos($m['id'], 'e5') !== false || strpos($m['id'], 'embed') !== false);
    
    return $twig->render($response, 'bot_settings.twig', [
        'bot' => $botData, 
        'models' => $models,
        'embeddingModels' => $embeddingModels,
        'currentPage' => 'bots'
    ]);
})->setName('bot-settings')->add($authMiddleware);

$app->post('/bots/{id}/settings', function (Request $request, Response $response, array $args) use ($db, $app) {
    $botId = (int)$args['id'];
    $data = $request->getParsedBody();
    
    $stmt = $db->prepare("
        UPDATE bots SET 
            bot_name = ?, 
            mention_name = ?, 
            default_model = ?, 
            system_prompt = ?, 
            onboarding_group_questions = ?, 
            onboarding_dm_questions = ?, 
            embedding_model = ?, 
            rag_top_k = ?, 
            rag_chunk_size = ?, 
            rag_chunk_overlap = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    // Ensure mention starts with @
    $mentionName = trim($data['mention_name'] ?? '');
    if (!str_starts_with($mentionName, '@')) {
        $mentionName = '@' . $mentionName;
    }
    
    $groupQuestions = json_encode(array_filter(array_map('trim', explode("\n", $data['onboarding_group_questions'] ?? ''))));
    $dmQuestions = json_encode(array_filter(array_map('trim', explode("\n", $data['onboarding_dm_questions'] ?? ''))));
    
    $stmt->execute([
        $data['bot_name'] ?? '',
        $mentionName,
        $data['default_model'] ?? 'meta-llama-3.1-8b-instruct',
        $data['system_prompt'] ?? '',
        $groupQuestions,
        $dmQuestions,
        $data['embedding_model'] ?? 'e5-mistral-7b-instruct',
        (int)($data['rag_top_k'] ?? 3),
        (int)($data['rag_chunk_size'] ?? 250),
        (int)($data['rag_chunk_overlap'] ?? 25),
        $botId
    ]);
    
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bot-settings', ['id' => $botId]))->withStatus(302);
})->setName('bot-settings-update')->add($authMiddleware);

$app->post('/bots/{id}/delete', function (Request $request, Response $response, array $args) use ($db, $app, $logger) {
    $botId = (int)$args['id'];
    
    try {
        $db->beginTransaction();
        
        // Delete the bot (cascade will handle related records)
        $stmt = $db->prepare("DELETE FROM bots WHERE id = ?");
        $stmt->execute([$botId]);
        
        $db->commit();
        $logger->info('Bot deleted successfully', ['bot_id' => $botId]);
        
    } catch (\PDOException $e) {
        $db->rollBack();
        $logger->error('Failed to delete bot', ['bot_id' => $botId, 'error' => $e->getMessage()]);
    }
    
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
})->setName('bot-delete')->add($authMiddleware);

$app->get('/documents', function (Request $request, Response $response) use ($twig, $db, $app) {
    $botId = $request->getQueryParams()['bot_id'] ?? null;
    $bots = $db->query("SELECT id, bot_name, mention_name FROM bots ORDER BY bot_name")->fetchAll();
    
    $docs = [];
    $selectedBot = null;
    
    if ($botId) {
        $stmt = $db->prepare("SELECT id, filename, created_at FROM bot_docs WHERE bot_id = ? ORDER BY created_at DESC");
        $stmt->execute([$botId]);
        $docs = $stmt->fetchAll();
        
        $botStmt = $db->prepare("SELECT * FROM bots WHERE id = ?");
        $botStmt->execute([$botId]);
        $selectedBot = $botStmt->fetch();
    } elseif (!empty($bots)) {
        // Default to first bot if none selected and redirect with bot_id parameter
        $firstBot = $bots[0];
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents') . '?bot_id=' . $firstBot['id'])->withStatus(302);
    }
    
    return $twig->render($response, 'documents.twig', [
        'docs' => $docs, 
        'bots' => $bots,
        'selectedBot' => $selectedBot,
        'currentPage' => 'documents'
    ]);
})->setName('documents')->add($authMiddleware);

$app->post('/documents/upload', function (Request $request, Response $response) use ($db, $embeddingService, $app) {
    $uploadedFile = $request->getUploadedFiles()['document'];
    $botId = $request->getParsedBody()['bot_id'] ?? null;
    
    if (!$botId) {
        return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents'))->withStatus(302);
    }
    
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
        $stmt = $db->prepare("SELECT id FROM bot_docs WHERE checksum = ? AND bot_id = ?");
        $stmt->execute([$checksum, $botId]);
        if ($stmt->fetch()) {
            unlink($targetPath);
        } else {
            $stmt = $db->prepare("INSERT INTO bot_docs (filename, path, checksum, bot_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$basename, $targetPath, $checksum, $botId]);
            $docId = $db->lastInsertId();
            $embeddingService->generateAndStoreEmbeddings((int)$docId, $content, (int)$botId);
        }
    }
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('documents') . '?bot_id=' . $botId)->withStatus(302);
})->setName('documents-upload')->add($authMiddleware);

// Async upload endpoint
$app->post('/documents/upload-async', function (Request $request, Response $response) use ($db, $logger) {
    try {
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['document'] ?? null;
        
        if (!$uploadedFile) {
            throw new \Exception('No file uploaded');
        }
        
        // Get bot_id from form data
        $parsedBody = $request->getParsedBody();
        $botId = $parsedBody['bot_id'] ?? null;
        
        $logger->info('Upload request received', [
            'bot_id' => $botId,
            'filename' => $uploadedFile->getClientFilename(),
            'upload_error' => $uploadedFile->getError()
        ]);
        
        if (!$botId) {
            throw new \Exception('Bot ID is required');
        }
        
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
        
        // Check for duplicates within the same bot
        $stmt = $db->prepare("SELECT id FROM bot_docs WHERE checksum = ? AND bot_id = ?");
        $stmt->execute([$checksum, $botId]);
        if ($existingDoc = $stmt->fetch()) {
            unlink($targetPath);
            throw new \Exception('Document already exists for this bot');
        }
        
        // Insert document record
        $stmt = $db->prepare("INSERT INTO bot_docs (filename, path, checksum, bot_id, processing_status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$basename, $targetPath, $checksum, $botId]);
        $docId = $db->lastInsertId();
        
        // Initialize progress tracking
        $stmt = $db->prepare("INSERT INTO bot_processing_progress (doc_id, status, progress, started_at) VALUES (?, 'started', 0, NOW())");
        $stmt->execute([$docId]);
        
        $logger->info('Document uploaded, starting background processing', ['doc_id' => $docId, 'filename' => $basename, 'bot_id' => $botId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'doc_id' => $docId,
            'filename' => $basename,
            'bot_id' => $botId
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
        // Get bot_id for the document
        $docStmt = $db->prepare("SELECT bot_id FROM bot_docs WHERE id = ?");
        $docStmt->execute([$docId]);
        $docInfo = $docStmt->fetch();
        $botId = $docInfo['bot_id'] ?? null;
        
        // Start processing in background
        $embeddingService->generateAndStoreEmbeddingsAsync($docId, file_get_contents($doc['path']), $botId);
        
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
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid JSON data: ' . json_last_error_msg()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        
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

// Legacy routes - kept for backward compatibility but deprecated
// These are no longer accessible from the main navigation
// All configuration is now done through the bot-specific settings pages

$app->get('/models', function (Request $request, Response $response) use ($app) {
    // Redirect to bots management page
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
})->setName('models')->add($authMiddleware);

$app->get('/prompt', function (Request $request, Response $response) use ($app) {
    // Redirect to bots management page
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
})->setName('prompt')->add($authMiddleware);

$app->get('/onboarding', function (Request $request, Response $response) use ($app) {
    // Redirect to bots management page
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
})->setName('onboarding')->add($authMiddleware);

$app->get('/rag-settings', function (Request $request, Response $response) use ($app) {
    // Redirect to bots management page
    return $response->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('bots'))->withStatus(302);
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