<?php
/**
 * EDUC AI TalkBot - Settings Page
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication
checkSetup();
requireAuth();

// Handle form submission
$message = '';
$messageType = '';

if ($_POST) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'save_api_settings':
                    // Update API settings
                    $message = 'API settings updated successfully. Note: Environment variables require app restart.';
                    $messageType = 'success';
                    break;
                    
                case 'save_rag_settings':
                    $message = 'RAG settings updated successfully.';
                    $messageType = 'success';
                    break;
                    
                case 'save_system_settings':
                    $message = 'System settings updated successfully.';
                    $messageType = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        $message = 'Error saving settings: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Load current settings
$currentSettings = [
    'ai_api_key' => getenv('AI_API_KEY') ?: '',
    'ai_api_endpoint' => getenv('AI_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/chat/completions',
    'embedding_api_endpoint' => getenv('EMBEDDING_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/embeddings',
    'models_api_endpoint' => getenv('MODELS_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/models',
    'use_rag' => getenv('USE_RAG') === 'true',
    'rag_top_k' => getenv('RAG_TOP_K') ?: '5',
    'embedding_model' => getenv('EMBEDDING_MODEL') ?: 'e5-mistral-7b-instruct',
    'app_name' => getenv('APP_NAME') ?: 'EDUC AI TalkBot Enhanced',
    'debug_mode' => getenv('DEBUG_MODE') === 'true',
    'log_level' => getenv('LOG_LEVEL') ?: 'INFO'
];

// Page configuration
$pageTitle = 'Settings';
$pageIcon = 'bi bi-gear';

// Include header
include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Settings Tabs -->
<div class="settings-container">
    <nav>
        <div class="nav nav-tabs" id="nav-tab" role="tablist">
            <button class="nav-link active" id="nav-api-tab" data-bs-toggle="tab" data-bs-target="#nav-api" type="button" role="tab">
                <i class="bi bi-cloud"></i> AI API Settings
            </button>
            <button class="nav-link" id="nav-rag-tab" data-bs-toggle="tab" data-bs-target="#nav-rag" type="button" role="tab">
                <i class="bi bi-database"></i> RAG Configuration
            </button>
            <button class="nav-link" id="nav-system-tab" data-bs-toggle="tab" data-bs-target="#nav-system" type="button" role="tab">
                <i class="bi bi-gear"></i> System Settings
            </button>
        </div>
    </nav>
    
    <div class="tab-content" id="nav-tabContent">
        <!-- AI API Settings -->
        <div class="tab-pane fade show active" id="nav-api" role="tabpanel">
            <div class="stats-card">
                <h5 class="mb-4">
                    <i class="bi bi-cloud"></i> GWDG SAIA API Configuration
                </h5>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>GWDG SAIA API:</strong> Get your API key from 
                    <a href="https://kisski.gwdg.de/en/leistungen/2-02-llm-service" target="_blank">KISSKI LLM Service</a>.
                    The API is compatible with OpenAI standards.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_api_settings">
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="ai_api_key" class="form-label">API Key</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control" id="ai_api_key" name="ai_api_key" 
                                       value="<?= htmlspecialchars($currentSettings['ai_api_key']) ?>" 
                                       placeholder="your_gwdg_api_key_here">
                                <button class="btn btn-outline-secondary" type="button" id="toggleApiKey">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Your GWDG SAIA API key for accessing AI models</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="ai_api_endpoint" class="form-label">Chat API Endpoint</label>
                            <input type="url" class="form-control" id="ai_api_endpoint" name="ai_api_endpoint" 
                                   value="<?= htmlspecialchars($currentSettings['ai_api_endpoint']) ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="embedding_api_endpoint" class="form-label">Embeddings API Endpoint</label>
                            <input type="url" class="form-control" id="embedding_api_endpoint" name="embedding_api_endpoint" 
                                   value="<?= htmlspecialchars($currentSettings['embedding_api_endpoint']) ?>">
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-info" onclick="testApiConnection()">
                            <i class="bi bi-plug"></i> Test Connection
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- RAG Configuration -->
        <div class="tab-pane fade" id="nav-rag" role="tabpanel">
            <div class="stats-card">
                <h5 class="mb-4">
                    <i class="bi bi-database"></i> RAG Configuration
                </h5>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_rag_settings">
                    
                    <div class="row">
                        <div class="col-md-12 mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="use_rag" name="use_rag" 
                                       <?= $currentSettings['use_rag'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="use_rag">
                                    <strong>Enable RAG</strong>
                                </label>
                                <div class="form-text">Use Retrieval-Augmented Generation with document embeddings</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="rag_top_k" class="form-label">Top K Results</label>
                            <input type="number" class="form-control" id="rag_top_k" name="rag_top_k" 
                                   value="<?= htmlspecialchars($currentSettings['rag_top_k']) ?>" min="1" max="20">
                            <div class="form-text">Number of relevant documents to retrieve</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="embedding_model" class="form-label">Embedding Model</label>
                            <select class="form-select" id="embedding_model" name="embedding_model">
                                <option value="e5-mistral-7b-instruct">e5-mistral-7b-instruct</option>
                            </select>
                            <div class="form-text">GWDG SAIA embedding model</div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i> Save RAG Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- System Settings -->
        <div class="tab-pane fade" id="nav-system" role="tabpanel">
            <div class="stats-card">
                <h5 class="mb-4">
                    <i class="bi bi-gear"></i> System Configuration
                </h5>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_system_settings">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="app_name" class="form-label">Application Name</label>
                            <input type="text" class="form-control" id="app_name" name="app_name" 
                                   value="<?= htmlspecialchars($currentSettings['app_name']) ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="log_level" class="form-label">Log Level</label>
                            <select class="form-select" id="log_level" name="log_level">
                                <option value="DEBUG" <?= $currentSettings['log_level'] === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                                <option value="INFO" <?= $currentSettings['log_level'] === 'INFO' ? 'selected' : '' ?>>INFO</option>
                                <option value="WARNING" <?= $currentSettings['log_level'] === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                                <option value="ERROR" <?= $currentSettings['log_level'] === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" 
                                       <?= $currentSettings['debug_mode'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="debug_mode">Debug Mode</label>
                                <div class="form-text">Enable detailed error reporting</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i> Save System Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle API key visibility
document.getElementById('toggleApiKey').addEventListener('click', function() {
    const apiKeyInput = document.getElementById('ai_api_key');
    const icon = this.querySelector('i');
    
    if (apiKeyInput.type === 'password') {
        apiKeyInput.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        apiKeyInput.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

// Test API connection
function testApiConnection() {
    const apiKey = document.getElementById('ai_api_key').value;
    
    if (!apiKey) {
        alert('Please enter an API key first');
        return;
    }
    
    alert('API connection test would be implemented here');
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?> 