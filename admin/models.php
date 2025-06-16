<?php
/**
 * EDUC AI TalkBot - AI Models Page
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication and setup
checkSetup();
requireAuth();

// Initialize LLM client for model fetching
$llmClient = null;
$availableModels = [];
$llmClientError = null;
$apiTestResult = null;

try {
    $apiKey = \EDUC\Core\Environment::get('AI_API_KEY');
    $apiEndpoint = \EDUC\Core\Environment::get('AI_API_ENDPOINT');
    
    if (!empty($apiKey) && !empty($apiEndpoint)) {
        $llmClient = new \EDUC\API\LLMClient(
            $apiKey,
            $apiEndpoint,
            \EDUC\Core\Environment::get('EMBEDDING_API_ENDPOINT'),
            \EDUC\Core\Environment::get('MODELS_API_ENDPOINT')
        );
        $availableModels = $llmClient->getAvailableModels();
    } else {
        $missingVars = [];
        if (empty($apiKey)) $missingVars[] = 'AI_API_KEY';
        if (empty($apiEndpoint)) $missingVars[] = 'AI_API_ENDPOINT';
        $llmClientError = 'Missing environment variables: ' . implode(', ', $missingVars);
    }
} catch (Exception $e) {
    $llmClientError = $e->getMessage();
    \EDUC\Utils\Logger::error('Failed to initialize LLM client in models', ['error' => $e->getMessage()]);
}

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !\EDUC\Utils\Security::validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'refresh_models':
                if ($llmClient) {
                    $availableModels = $llmClient->getAvailableModels(false); // Force refresh
                    $message = 'Models refreshed successfully! Found ' . count($availableModels) . ' models.';
                    $messageType = 'success';
                } else {
                    $message = 'Cannot refresh models: API client not available';
                    $messageType = 'error';
                }
                break;
                
            case 'test_api':
                $apiTestResult = testAPIConnection($llmClient);
                $message = $apiTestResult['message'];
                $messageType = $apiTestResult['type'];
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Models page error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

/**
 * Test API connection
 */
function testAPIConnection(?\EDUC\API\LLMClient $llmClient): array {
    if (!$llmClient) {
        return [
            'message' => 'LLM client not available',
            'type' => 'error'
        ];
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
        return [
            'message' => 'API test failed: ' . $e->getMessage(),
            'type' => 'error'
        ];
    }
}

/**
 * Get GWDG SAIA model information based on scraped documentation
 */
function getGWDGModelInfo(): array {
    return [
        'meta-llama-3.1-8b-instruct' => [
            'name' => 'Meta Llama 3.1 8B Instruct',
            'capabilities' => ['text'],
            'description' => 'Fast and efficient text generation model suitable for general conversations and instruction following.',
            'use_cases' => ['General chat', 'Q&A', 'Text completion'],
            'context_window' => '8192 tokens',
            'provider' => 'Meta'
        ],
        'meta-llama-3.1-8b-rag' => [
            'name' => 'Meta Llama 3.1 8B RAG',
            'capabilities' => ['text', 'arcana'],
            'description' => 'RAG-optimized version with enhanced document retrieval and knowledge integration capabilities.',
            'use_cases' => ['Document Q&A', 'Knowledge retrieval', 'RAG applications'],
            'context_window' => '8192 tokens',
            'provider' => 'Meta'
        ],
        'llama-3.1-sauerkrautlm-70b-instruct' => [
            'name' => 'Llama 3.1 SauerkrautLM 70B',
            'capabilities' => ['text', 'arcana'],
            'description' => 'Large German-optimized model with excellent performance on complex tasks and German language understanding.',
            'use_cases' => ['Complex reasoning', 'German language tasks', 'Professional applications'],
            'context_window' => '8192 tokens',
            'provider' => 'DFKI'
        ],
        'llama-3.3-70b-instruct' => [
            'name' => 'Llama 3.3 70B Instruct',
            'capabilities' => ['text'],
            'description' => 'Latest large language model with improved reasoning and instruction following capabilities.',
            'use_cases' => ['Complex reasoning', 'Professional writing', 'Advanced Q&A'],
            'context_window' => '8192 tokens',
            'provider' => 'Meta'
        ],
        'gemma-3-27b-it' => [
            'name' => 'Google Gemma 3 27B IT',
            'capabilities' => ['text', 'image'],
            'description' => 'Multimodal model capable of understanding both text and images with strong reasoning abilities.',
            'use_cases' => ['Image analysis', 'Multimodal Q&A', 'Visual reasoning'],
            'context_window' => '8192 tokens',
            'provider' => 'Google'
        ],
        'mistral-large-instruct' => [
            'name' => 'Mistral Large Instruct',
            'capabilities' => ['text'],
            'description' => 'High-performance model optimized for instruction following and complex reasoning tasks.',
            'use_cases' => ['Complex instructions', 'Code generation', 'Technical writing'],
            'context_window' => '32768 tokens',
            'provider' => 'Mistral AI'
        ],
        'qwen3-32b' => [
            'name' => 'Qwen 3 32B',
            'capabilities' => ['text'],
            'description' => 'Alibaba\'s large language model with strong multilingual capabilities and reasoning skills.',
            'use_cases' => ['Multilingual tasks', 'General conversation', 'Text analysis'],
            'context_window' => '8192 tokens',
            'provider' => 'Alibaba'
        ],
        'qwen3-235b-a22b' => [
            'name' => 'Qwen 3 235B A22B',
            'capabilities' => ['text'],
            'description' => 'Extremely large model with exceptional performance on complex reasoning and understanding tasks.',
            'use_cases' => ['Advanced reasoning', 'Research tasks', 'Complex analysis'],
            'context_window' => '8192 tokens',
            'provider' => 'Alibaba'
        ],
        'qwen2.5-coder-32b-instruct' => [
            'name' => 'Qwen 2.5 Coder 32B',
            'capabilities' => ['text', 'code'],
            'description' => 'Specialized coding model with excellent programming capabilities across multiple languages.',
            'use_cases' => ['Code generation', 'Code review', 'Programming assistance'],
            'context_window' => '8192 tokens',
            'provider' => 'Alibaba'
        ],
        'codestral-22b' => [
            'name' => 'Codestral 22B',
            'capabilities' => ['text', 'code'],
            'description' => 'Mistral\'s specialized coding model optimized for software development tasks.',
            'use_cases' => ['Code completion', 'Bug fixing', 'Technical documentation'],
            'context_window' => '32768 tokens',
            'provider' => 'Mistral AI'
        ],
        'internvl2.5-8b' => [
            'name' => 'InternVL 2.5 8B',
            'capabilities' => ['text', 'image'],
            'description' => 'Vision-language model capable of understanding and reasoning about images and text.',
            'use_cases' => ['Image captioning', 'Visual Q&A', 'Document analysis'],
            'context_window' => '8192 tokens',
            'provider' => 'InternLM'
        ],
        'qwen-2.5-vl-72b-instruct' => [
            'name' => 'Qwen 2.5 VL 72B',
            'capabilities' => ['text', 'image'],
            'description' => 'Large vision-language model with advanced multimodal understanding capabilities.',
            'use_cases' => ['Complex image analysis', 'Multimodal reasoning', 'Visual content creation'],
            'context_window' => '8192 tokens',
            'provider' => 'Alibaba'
        ],
        'qwq-32b' => [
            'name' => 'Qwen QwQ 32B',
            'capabilities' => ['reasoning'],
            'description' => 'Specialized reasoning model designed for complex logical and mathematical problem solving.',
            'use_cases' => ['Mathematical reasoning', 'Logical puzzles', 'Scientific analysis'],
            'context_window' => '32768 tokens',
            'provider' => 'Alibaba'
        ],
        'deepseek-r1' => [
            'name' => 'DeepSeek R1',
            'capabilities' => ['reasoning'],
            'description' => 'Advanced reasoning model with exceptional performance on complex cognitive tasks.',
            'use_cases' => ['Complex reasoning', 'Research assistance', 'Problem solving'],
            'context_window' => '8192 tokens',
            'provider' => 'DeepSeek'
        ],
        'e5-mistral-7b-instruct' => [
            'name' => 'E5 Mistral 7B (Embeddings)',
            'capabilities' => ['embeddings'],
            'description' => 'Specialized embedding model for converting text into high-quality vector representations.',
            'use_cases' => ['Text embeddings', 'Semantic search', 'RAG applications'],
            'context_window' => '512 tokens',
            'provider' => 'Microsoft'
        ]
    ];
}

$gwdgModelInfo = getGWDGModelInfo();
$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

// Get current model setting
$db = \EDUC\Database\Database::getInstance();
$settings = $db->getAllSettings();
$currentModel = $settings['model']['value'] ?? 'meta-llama-3.1-8b-instruct';

// Page configuration
$pageTitle = 'AI Models';
$pageIcon = 'bi bi-cpu';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Models Content -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- API Status Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-wifi"></i> GWDG SAIA API Status
                </h5>
                <div>
                    <form method="post" action="" class="d-inline me-2">
                        <input type="hidden" name="action" value="test_api">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-clockwise"></i> Test Connection
                        </button>
                    </form>
                    
                    <form method="post" action="" class="d-inline">
                        <input type="hidden" name="action" value="refresh_models">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button type="submit" class="btn btn-outline-success btn-sm" <?= $llmClientError ? 'disabled' : '' ?>>
                            <i class="bi bi-arrow-clockwise"></i> Refresh Models
                        </button>
                    </form>
                </div>
            </div>
            
            <?php if ($llmClientError): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>API Configuration Required</strong><br>
                    <?= htmlspecialchars($llmClientError) ?><br>
                    <small class="mt-2 d-block">
                        Configure the following environment variables:
                        <code>AI_API_KEY</code>, <code>AI_API_ENDPOINT</code>, 
                        <code>EMBEDDING_API_ENDPOINT</code>, <code>MODELS_API_ENDPOINT</code>
                    </small>
                </div>
            <?php else: ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="text-success mb-2">
                                <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                            </div>
                            <strong>API Connected</strong>
                            <div class="text-muted small">GWDG SAIA</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="text-info mb-2" style="font-size: 2rem;">
                                <?= count($availableModels) ?>
                            </div>
                            <strong>Available Models</strong>
                            <div class="text-muted small">Total Count</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="text-primary mb-2">
                                <i class="bi bi-star-fill" style="font-size: 2rem;"></i>
                            </div>
                            <strong>Current Model</strong>
                            <div class="text-muted small"><?= htmlspecialchars($currentModel) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="text-warning mb-2">
                                <i class="bi bi-speedometer2" style="font-size: 2rem;"></i>
                            </div>
                            <strong>Endpoint</strong>
                            <div class="text-muted small">chat-ai.academiccloud.de</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Models Grid -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="bi bi-cpu"></i> Available Models
                    <?php if (!empty($availableModels)): ?>
                        <span class="badge bg-primary ms-2"><?= count($availableModels) ?></span>
                    <?php endif; ?>
                </h5>
                
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="view-mode" id="grid-view" autocomplete="off" checked>
                    <label class="btn btn-outline-secondary btn-sm" for="grid-view">
                        <i class="bi bi-grid"></i>
                    </label>
                    
                    <input type="radio" class="btn-check" name="view-mode" id="list-view" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="list-view">
                        <i class="bi bi-list"></i>
                    </label>
                </div>
            </div>
            
            <?php if (empty($availableModels) && !$llmClientError): ?>
                <div class="text-center py-4">
                    <i class="bi bi-cpu" style="font-size: 3rem; color: #e5e7eb;"></i>
                    <p class="text-muted mt-2">No models available</p>
                    <p class="text-muted">Check your API configuration</p>
                </div>
            <?php else: ?>
                <!-- Grid View -->
                <div id="models-grid" class="row">
                    <?php 
                    $modelsToShow = !empty($availableModels) ? $availableModels : array_keys($gwdgModelInfo);
                    foreach ($modelsToShow as $model): 
                        $modelId = is_array($model) ? $model['id'] : $model;
                        $modelInfo = $gwdgModelInfo[$modelId] ?? null;
                        $isActive = $currentModel === $modelId;
                    ?>
                        <div class="col-lg-6 col-xl-4 mb-4">
                            <div class="card h-100 <?= $isActive ? 'border-primary' : '' ?>">
                                <?php if ($isActive): ?>
                                    <div class="card-header bg-primary text-white">
                                        <i class="bi bi-star-fill"></i> Currently Active
                                    </div>
                                <?php endif; ?>
                                
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title mb-0">
                                            <?= htmlspecialchars($modelInfo['name'] ?? $modelId) ?>
                                        </h6>
                                        <small class="text-muted"><?= $modelInfo['provider'] ?? 'GWDG' ?></small>
                                    </div>
                                    
                                    <code class="small d-block mb-3"><?= htmlspecialchars($modelId) ?></code>
                                    
                                    <?php if ($modelInfo): ?>
                                        <p class="card-text small text-muted mb-3">
                                            <?= htmlspecialchars($modelInfo['description']) ?>
                                        </p>
                                        
                                        <div class="mb-3">
                                            <?php foreach ($modelInfo['capabilities'] as $capability): ?>
                                                <?php
                                                $badgeClass = match($capability) {
                                                    'text' => 'primary',
                                                    'image' => 'success',
                                                    'code' => 'info',
                                                    'reasoning' => 'warning',
                                                    'embeddings' => 'secondary',
                                                    'arcana' => 'dark',
                                                    default => 'light'
                                                };
                                                ?>
                                                <span class="badge bg-<?= $badgeClass ?> me-1 mb-1">
                                                    <?= ucfirst($capability) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="small text-muted">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Context:</span>
                                                <span><?= $modelInfo['context_window'] ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="card-text small text-muted">
                                            Model information not available in documentation.
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($modelInfo && !empty($modelInfo['use_cases'])): ?>
                                    <div class="card-footer bg-light">
                                        <small class="text-muted">
                                            <strong>Use cases:</strong> 
                                            <?= implode(', ', array_slice($modelInfo['use_cases'], 0, 3)) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- List View (Hidden by default) -->
                <div id="models-list" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th>Capabilities</th>
                                    <th>Provider</th>
                                    <th>Context</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modelsToShow as $model): 
                                    $modelId = is_array($model) ? $model['id'] : $model;
                                    $modelInfo = $gwdgModelInfo[$modelId] ?? null;
                                    $isActive = $currentModel === $modelId;
                                ?>
                                    <tr class="<?= $isActive ? 'table-primary' : '' ?>">
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($modelInfo['name'] ?? $modelId) ?></strong>
                                                <?php if ($isActive): ?>
                                                    <span class="badge bg-primary ms-2">Active</span>
                                                <?php endif; ?>
                                            </div>
                                            <code class="small"><?= htmlspecialchars($modelId) ?></code>
                                        </td>
                                        <td>
                                            <?php if ($modelInfo): ?>
                                                <?php foreach ($modelInfo['capabilities'] as $capability): ?>
                                                    <span class="badge bg-secondary me-1"><?= ucfirst($capability) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $modelInfo['provider'] ?? 'GWDG' ?></td>
                                        <td><?= $modelInfo['context_window'] ?? 'Unknown' ?></td>
                                        <td>
                                            <?php if (in_array($modelId, array_column($availableModels, 'id'))): ?>
                                                <span class="badge bg-success">Available</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Documented</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- GWDG Documentation Reference -->
<div class="row mt-4">
    <div class="col-12">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-book"></i> GWDG SAIA Documentation
            </h5>
            
            <p class="text-muted mb-3">
                Models are provided by the GWDG SAIA (Scalable Artificial Intelligence Accelerator) service.
                For detailed documentation, API usage examples, and booking information, visit:
            </p>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-link-45deg text-primary mb-2" style="font-size: 2rem;"></i>
                        <h6>SAIA Service</h6>
                        <a href="https://docs.hpc.gwdg.de/services/saia/" target="_blank" class="btn btn-outline-primary btn-sm">
                            Visit Documentation
                        </a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-key text-warning mb-2" style="font-size: 2rem;"></i>
                        <h6>API Key</h6>
                        <a href="https://kisski.gwdg.de/en/leistungen/2-02-llm-service" target="_blank" class="btn btn-outline-warning btn-sm">
                            Request Access
                        </a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-code-square text-info mb-2" style="font-size: 2rem;"></i>
                        <h6>API Reference</h6>
                        <a href="https://platform.openai.com/docs/api-reference/chat" target="_blank" class="btn btn-outline-info btn-sm">
                            OpenAI Compatible
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// View mode toggle
document.addEventListener('DOMContentLoaded', function() {
    const gridView = document.getElementById('grid-view');
    const listView = document.getElementById('list-view');
    const modelsGrid = document.getElementById('models-grid');
    const modelsList = document.getElementById('models-list');
    
    gridView.addEventListener('change', function() {
        if (this.checked) {
            modelsGrid.classList.remove('d-none');
            modelsList.classList.add('d-none');
        }
    });
    
    listView.addEventListener('change', function() {
        if (this.checked) {
            modelsGrid.classList.add('d-none');
            modelsList.classList.remove('d-none');
        }
    });
});
</script>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?> 