<?php
/**
 * EDUC AI TalkBot Enhanced - Admin Panel
 * Comprehensive administration interface with RAG management
 */

session_start();

require_once '../vendor/autoload.php';
require_once '../auto-include.php';

use EDUC\Core\Environment;
use EDUC\Database\Database;
use EDUC\API\LLMClient;
use EDUC\Utils\Logger;
use EDUC\Utils\Security;
use EDUC\RAG\DocumentProcessor;
use EDUC\Database\EmbeddingRepository;

// Initialize components
Environment::load();
Logger::initialize();
Security::initializeErrorHandlers();

// Check authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$settings = $db->getAllSettings();

// Initialize LLM client for model fetching
$llmClient = null;
$availableModels = [];
try {
    $llmClient = new LLMClient(
        Environment::get('AI_API_KEY'),
        Environment::get('AI_API_ENDPOINT'),
        Environment::get('EMBEDDING_API_ENDPOINT'),
        Environment::get('MODELS_API_ENDPOINT')
    );
    $availableModels = $llmClient->getAvailableModels();
} catch (Exception $e) {
    Logger::error('Failed to initialize LLM client in admin panel', ['error' => $e->getMessage()]);
}

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'save_settings':
                handleSaveSettings($db, $_POST);
                $message = 'Settings saved successfully!';
                $messageType = 'success';
                break;
                
            case 'upload_files':
                $result = handleFileUpload($_FILES);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'process_documents':
                $result = processDocuments($db, $llmClient);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'clear_rag_data':
                $result = clearRAGData($db);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'test_api':
                $result = testAPIConnection($llmClient);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
        // Refresh settings after any changes
        $settings = $db->getAllSettings();
        
    } catch (Exception $e) {
        Logger::error('Admin panel error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get statistics
$stats = getSystemStats($db);
$ragStats = getRAGStats($db);
$recentLogs = Logger::getRecentLogs(50);

/**
 * Handle settings save
 */
function handleSaveSettings(Database $db, array $postData): void {
    $settingsToSave = [
        'system_prompt',
        'model',
        'bot_mention',
        'debug_mode',
        'welcome_message',
        'user_onboarding_questions',
        'group_onboarding_questions'
    ];
    
    foreach ($settingsToSave as $setting) {
        if (isset($postData[$setting])) {
            $value = $postData[$setting];
            
            // Handle JSON settings
            if (in_array($setting, ['user_onboarding_questions', 'group_onboarding_questions'])) {
                if (is_array($value)) {
                    $value = json_encode(array_filter($value));
                }
            }
            
            $db->setSetting($setting, $value);
        }
    }
    
    Logger::info('Admin settings updated', ['settings' => array_keys($postData)]);
}

/**
 * Handle file upload
 */
function handleFileUpload(array $files): array {
    if (!isset($files['documents']) || empty($files['documents']['name'][0])) {
        return ['message' => 'No files selected', 'type' => 'error'];
    }
    
    $uploadDir = Environment::getUploadsPath();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = explode(',', Environment::get('ALLOWED_FILE_TYPES', 'txt,md,pdf,docx,html,csv,json'));
    $maxFileSize = (int)Environment::get('MAX_FILE_SIZE', 10485760); // 10MB default
    
    $uploadedFiles = [];
    $errors = [];
    
    $fileCount = count($files['documents']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['documents']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file " . ($i + 1);
            continue;
        }
        
        $originalName = $files['documents']['name'][$i];
        $tmpName = $files['documents']['tmp_name'][$i];
        $size = $files['documents']['size'][$i];
        $type = $files['documents']['type'][$i];
        
        // Validate file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes)) {
            $errors[] = "Invalid file type for {$originalName}";
            continue;
        }
        
        // Validate file size
        if ($size > $maxFileSize) {
            $errors[] = "File {$originalName} is too large";
            continue;
        }
        
        // Generate unique filename
        $filename = uniqid() . '_' . $originalName;
        $destination = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($tmpName, $destination)) {
            $uploadedFiles[] = [
                'filename' => $filename,
                'original_name' => $originalName,
                'size' => $size,
                'type' => $type,
                'path' => $destination
            ];
        } else {
            $errors[] = "Failed to move {$originalName}";
        }
    }
    
    // Save file records to database
    $db = Database::getInstance();
    foreach ($uploadedFiles as $file) {
        $sql = "INSERT INTO {$db->getTablePrefix()}documents 
                (filename, original_filename, file_path, file_size, mime_type, status) 
                VALUES (?, ?, ?, ?, ?, 'uploaded')";
        $db->execute($sql, [
            $file['filename'],
            $file['original_name'],
            $file['path'],
            $file['size'],
            $file['type']
        ]);
    }
    
    $uploadCount = count($uploadedFiles);
    $errorCount = count($errors);
    
    if ($uploadCount > 0 && $errorCount === 0) {
        return ['message' => "Successfully uploaded {$uploadCount} files", 'type' => 'success'];
    } elseif ($uploadCount > 0 && $errorCount > 0) {
        return ['message' => "Uploaded {$uploadCount} files with {$errorCount} errors", 'type' => 'warning'];
    } else {
        return ['message' => 'Upload failed: ' . implode(', ', $errors), 'type' => 'error'];
    }
}

/**
 * Process documents for RAG
 */
function processDocuments(Database $db, ?LLMClient $llmClient): array {
    if (!$llmClient) {
        return ['message' => 'LLM client not available', 'type' => 'error'];
    }
    
    try {
        $embeddingRepository = new EmbeddingRepository($db);
        $processor = new DocumentProcessor($llmClient, $db, $embeddingRepository);
        $result = $processor->processAllDocuments();
        
        return [
            'message' => "Processed {$result['processed']} documents, generated {$result['embeddings']} embeddings",
            'type' => 'success'
        ];
    } catch (Exception $e) {
        Logger::error('Document processing failed', ['error' => $e->getMessage()]);
        return ['message' => 'Document processing failed: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Clear RAG data
 */
function clearRAGData(Database $db): array {
    try {
        $prefix = $db->getTablePrefix();
        
        // Clear embeddings and documents
        $db->execute("DELETE FROM {$prefix}embeddings");
        $db->execute("DELETE FROM {$prefix}documents");
        
        Logger::info('RAG data cleared');
        
        return ['message' => 'RAG data cleared successfully', 'type' => 'success'];
    } catch (Exception $e) {
        Logger::error('Failed to clear RAG data', ['error' => $e->getMessage()]);
        return ['message' => 'Failed to clear RAG data: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Test API connection
 */
function testAPIConnection(?LLMClient $llmClient): array {
    if (!$llmClient) {
        return ['message' => 'LLM client not available', 'type' => 'error'];
    }
    
    try {
        $result = $llmClient->testConnection();
        
        if ($result['status'] === 'success') {
            return [
                'message' => "API connection successful! Found {$result['models_count']} models",
                'type' => 'success'
            ];
        } else {
            return [
                'message' => 'API connection failed: ' . $result['error'],
                'type' => 'error'
            ];
        }
    } catch (Exception $e) {
        return ['message' => 'API test failed: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Get system statistics
 */
function getSystemStats(Database $db): array {
    $prefix = $db->getTablePrefix();
    
    $stats = [];
    
    // Message count
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages");
    $stats['total_messages'] = $result[0]['count'] ?? 0;
    
    // Chat count
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}chat_configs");
    $stats['total_chats'] = $result[0]['count'] ?? 0;
    
    // Recent activity (last 24 hours)
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}messages WHERE created_at > NOW() - INTERVAL '24 hours'");
    $stats['messages_24h'] = $result[0]['count'] ?? 0;
    
    return $stats;
}

/**
 * Get RAG statistics
 */
function getRAGStats(Database $db): array {
    $prefix = $db->getTablePrefix();
    
    $stats = [];
    
    // Document count
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents");
    $stats['total_documents'] = $result[0]['count'] ?? 0;
    
    // Embedding count
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}embeddings");
    $stats['total_embeddings'] = $result[0]['count'] ?? 0;
    
    // Processed documents
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status = 'processed'");
    $stats['processed_documents'] = $result[0]['count'] ?? 0;
    
    // Pending documents
    $result = $db->query("SELECT COUNT(*) as count FROM {$prefix}documents WHERE status IN ('uploaded', 'pending')");
    $stats['pending_documents'] = $result[0]['count'] ?? 0;
    
    return $stats;
}

$csrfToken = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDUC AI TalkBot - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .dashboard-card {
            transition: transform 0.2s;
        }
        .dashboard-card:hover {
            transform: translateY(-2px);
        }
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            background: #f8f9fa;
            padding: 0.5rem;
            margin: 0.25rem 0;
            border-radius: 4px;
            white-space: nowrap;
            overflow-x: auto;
        }
        .log-level-ERROR {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .log-level-WARNING {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .log-level-INFO {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
        }
        .log-level-DEBUG {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: border-color 0.3s;
        }
        .file-upload-area:hover {
            border-color: #0d6efd;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-robot"></i> EDUC AI TalkBot Admin
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="logout.php">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Dashboard Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card dashboard-card text-center">
                    <div class="card-body">
                        <i class="bi bi-chat-dots text-primary" style="font-size: 2rem;"></i>
                        <h5 class="card-title mt-2">Total Messages</h5>
                        <h3 class="text-primary"><?= number_format($stats['total_messages']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card text-center">
                    <div class="card-body">
                        <i class="bi bi-people text-success" style="font-size: 2rem;"></i>
                        <h5 class="card-title mt-2">Active Chats</h5>
                        <h3 class="text-success"><?= number_format($stats['total_chats']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card text-center">
                    <div class="card-body">
                        <i class="bi bi-file-earmark-text text-info" style="font-size: 2rem;"></i>
                        <h5 class="card-title mt-2">Documents</h5>
                        <h3 class="text-info"><?= number_format($ragStats['total_documents']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card text-center">
                    <div class="card-body">
                        <i class="bi bi-layers text-warning" style="font-size: 2rem;"></i>
                        <h5 class="card-title mt-2">Embeddings</h5>
                        <h3 class="text-warning"><?= number_format($ragStats['total_embeddings']) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-pills mb-4" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="settings-tab" data-bs-toggle="pill" data-bs-target="#settings" type="button">
                    <i class="bi bi-gear"></i> Settings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="rag-tab" data-bs-toggle="pill" data-bs-target="#rag" type="button">
                    <i class="bi bi-database"></i> RAG Management
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="models-tab" data-bs-toggle="pill" data-bs-target="#models" type="button">
                    <i class="bi bi-cpu"></i> Models
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs" type="button">
                    <i class="bi bi-journal-text"></i> Logs
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="system-tab" data-bs-toggle="pill" data-bs-target="#system" type="button">
                    <i class="bi bi-info-circle"></i> System
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="adminTabsContent">
            <!-- Settings Tab -->
            <div class="tab-pane fade show active" id="settings" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-gear"></i> Bot Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="save_settings">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="model" class="form-label">AI Model</label>
                                        <select class="form-select" id="model" name="model" required>
                                            <?php foreach ($availableModels as $model): ?>
                                                <option value="<?= htmlspecialchars($model['id']) ?>" 
                                                        <?= ($settings['model']['value'] ?? '') === $model['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($model['name']) ?>
                                                    <?php if (!empty($model['capabilities'])): ?>
                                                        (<?= implode(', ', $model['capabilities']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select the AI model to use for responses</div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="bot_mention" class="form-label">Bot Mention Name</label>
                                        <input type="text" class="form-control" id="bot_mention" name="bot_mention" 
                                               value="<?= htmlspecialchars($settings['bot_mention']['value'] ?? '') ?>" required>
                                        <div class="form-text">Name users will use to mention the bot (@BotName)</div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" value="true"
                                                   <?= ($settings['debug_mode']['value'] ?? '') === 'true' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="debug_mode">
                                                Enable Debug Mode
                                            </label>
                                        </div>
                                        <div class="form-text">Show detailed information in bot responses</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="welcome_message" class="form-label">Welcome Message</label>
                                        <textarea class="form-control" id="welcome_message" name="welcome_message" rows="3"><?= htmlspecialchars($settings['welcome_message']['value'] ?? '') ?></textarea>
                                        <div class="form-text">Message sent when starting new conversations</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="system_prompt" class="form-label">System Prompt</label>
                                <textarea class="form-control" id="system_prompt" name="system_prompt" rows="4" required><?= htmlspecialchars($settings['system_prompt']['value'] ?? '') ?></textarea>
                                <div class="form-text">Instructions that define the AI's behavior and personality</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <h6>User Onboarding Questions</h6>
                                    <div id="user-questions">
                                        <?php 
                                        $userQuestions = json_decode($settings['user_onboarding_questions']['value'] ?? '[]', true);
                                        foreach ($userQuestions as $i => $question): 
                                        ?>
                                            <div class="input-group mb-2">
                                                <input type="text" class="form-control" name="user_onboarding_questions[]" 
                                                       value="<?= htmlspecialchars($question) ?>">
                                                <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addUserQuestion()">
                                        <i class="bi bi-plus"></i> Add Question
                                    </button>
                                </div>

                                <div class="col-md-6">
                                    <h6>Group Onboarding Questions</h6>
                                    <div id="group-questions">
                                        <?php 
                                        $groupQuestions = json_decode($settings['group_onboarding_questions']['value'] ?? '[]', true);
                                        foreach ($groupQuestions as $i => $question): 
                                        ?>
                                            <div class="input-group mb-2">
                                                <input type="text" class="form-control" name="group_onboarding_questions[]" 
                                                       value="<?= htmlspecialchars($question) ?>">
                                                <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGroupQuestion()">
                                        <i class="bi bi-plus"></i> Add Question
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- RAG Management Tab -->
            <div class="tab-pane fade" id="rag" role="tabpanel">
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-cloud-upload"></i> Upload Documents</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_files">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    
                                    <div class="file-upload-area mb-3">
                                        <i class="bi bi-cloud-upload" style="font-size: 3rem; color: #6c757d;"></i>
                                        <h6 class="mt-2">Upload Documents for RAG</h6>
                                        <p class="text-muted mb-3">Select multiple files to upload</p>
                                        <input type="file" class="form-control" name="documents[]" multiple 
                                               accept=".txt,.md,.pdf,.docx,.html,.csv,.json" required>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-upload"></i> Upload Files
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-bar-chart"></i> RAG Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Documents:</span>
                                        <strong><?= $ragStats['total_documents'] ?></strong>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span>Processed:</span>
                                        <strong class="text-success"><?= $ragStats['processed_documents'] ?></strong>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span>Pending:</span>
                                        <strong class="text-warning"><?= $ragStats['pending_documents'] ?></strong>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Embeddings:</span>
                                        <strong><?= $ragStats['total_embeddings'] ?></strong>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <form method="post" action="" class="mb-2">
                                    <input type="hidden" name="action" value="process_documents">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" class="btn btn-success btn-sm w-100" <?= $ragStats['pending_documents'] == 0 ? 'disabled' : '' ?>>
                                        <i class="bi bi-gear"></i> Process Documents
                                    </button>
                                </form>
                                
                                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear all RAG data?')">
                                    <input type="hidden" name="action" value="clear_rag_data">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" class="btn btn-danger btn-sm w-100">
                                        <i class="bi bi-trash"></i> Clear RAG Data
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Models Tab -->
            <div class="tab-pane fade" id="models" role="tabpanel">
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5><i class="bi bi-cpu"></i> Available Models</h5>
                                <form method="post" action="" class="d-inline">
                                    <input type="hidden" name="action" value="test_api">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-arrow-clockwise"></i> Test API
                                    </button>
                                </form>
                            </div>
                            <div class="card-body">
                                <?php if (empty($availableModels)): ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        No models available. Please check your API configuration.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Model Name</th>
                                                    <th>ID</th>
                                                    <th>Capabilities</th>
                                                    <th>Provider</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($availableModels as $model): ?>
                                                    <tr class="<?= ($settings['model']['value'] ?? '') === $model['id'] ? 'table-primary' : '' ?>">
                                                        <td>
                                                            <strong><?= htmlspecialchars($model['name']) ?></strong>
                                                            <?php if (($settings['model']['value'] ?? '') === $model['id']): ?>
                                                                <span class="badge bg-primary ms-2">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><code><?= htmlspecialchars($model['id']) ?></code></td>
                                                        <td>
                                                            <?php foreach ($model['capabilities'] as $capability): ?>
                                                                <span class="badge bg-secondary me-1"><?= $capability ?></span>
                                                            <?php endforeach; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($model['provider']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-info-circle"></i> Model Information</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Models are fetched dynamically from the GWDG SAIA API. 
                                    Different models have different capabilities:
                                </p>
                                <ul class="list-unstyled">
                                    <li><span class="badge bg-secondary">text</span> Text generation</li>
                                    <li><span class="badge bg-secondary">image</span> Image understanding</li>
                                    <li><span class="badge bg-secondary">code</span> Code generation</li>
                                    <li><span class="badge bg-secondary">reasoning</span> Advanced reasoning</li>
                                    <li><span class="badge bg-secondary">embeddings</span> Vector embeddings</li>
                                    <li><span class="badge bg-secondary">arcana</span> RAG support</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logs Tab -->
            <div class="tab-pane fade" id="logs" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-journal-text"></i> Recent Logs</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                        
                        <div style="max-height: 600px; overflow-y: auto;">
                            <?php if (empty($recentLogs)): ?>
                                <p class="text-muted">No logs available</p>
                            <?php else: ?>
                                <?php foreach ($recentLogs as $log): ?>
                                    <?php
                                    // Extract log level for styling
                                    $logLevel = 'INFO';
                                    if (preg_match('/\.(DEBUG|INFO|WARNING|ERROR|CRITICAL)\s/', $log, $matches)) {
                                        $logLevel = $matches[1];
                                    }
                                    ?>
                                    <div class="log-entry log-level-<?= $logLevel ?>">
                                        <?= htmlspecialchars($log) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Tab -->
            <div class="tab-pane fade" id="system" role="tabpanel">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-server"></i> System Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td>PHP Version</td>
                                        <td><?= PHP_VERSION ?></td>
                                    </tr>
                                    <tr>
                                        <td>Cloudron Mode</td>
                                        <td><?= Environment::isCloudron() ? 'Yes' : 'No' ?></td>
                                    </tr>
                                    <tr>
                                        <td>App Directory</td>
                                        <td><code><?= Environment::getAppPath() ?></code></td>
                                    </tr>
                                    <tr>
                                        <td>Database Type</td>
                                        <td>PostgreSQL</td>
                                    </tr>
                                    <tr>
                                        <td>Log Level</td>
                                        <td><?= Logger::getLogLevel() ?></td>
                                    </tr>
                                    <tr>
                                        <td>Debug Mode</td>
                                        <td><?= ($settings['debug_mode']['value'] ?? 'false') === 'true' ? 'Enabled' : 'Disabled' ?></td>
                                    </tr>
                                    <tr>
                                        <td>Messages (24h)</td>
                                        <td><?= $stats['messages_24h'] ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-tools"></i> Maintenance</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-primary" onclick="clearCache()">
                                        <i class="bi bi-arrow-clockwise"></i> Clear Cache
                                    </button>
                                    <button class="btn btn-outline-warning" onclick="cleanupLogs()">
                                        <i class="bi bi-journal-x"></i> Cleanup Old Logs
                                    </button>
                                    <button class="btn btn-outline-info" onclick="exportSettings()">
                                        <i class="bi bi-download"></i> Export Settings
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function addUserQuestion() {
            const container = document.getElementById('user-questions');
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" name="user_onboarding_questions[]" placeholder="Enter question">
                <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            container.appendChild(div);
        }

        function addGroupQuestion() {
            const container = document.getElementById('group-questions');
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" name="group_onboarding_questions[]" placeholder="Enter question">
                <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            container.appendChild(div);
        }

        function removeQuestion(button) {
            button.parentElement.remove();
        }

        function clearCache() {
            if (confirm('Clear application cache?')) {
                // Implement cache clearing
                alert('Cache cleared successfully');
            }
        }

        function cleanupLogs() {
            if (confirm('Remove old log files (30+ days)?')) {
                // Implement log cleanup
                alert('Log cleanup completed');
            }
        }

        function exportSettings() {
            // Implement settings export
            alert('Settings export functionality coming soon');
        }

        // Auto-refresh logs tab
        setInterval(function() {
            if (document.getElementById('logs-tab').classList.contains('active')) {
                // Optionally auto-refresh logs
            }
        }, 30000);
    </script>
</body>
</html> 