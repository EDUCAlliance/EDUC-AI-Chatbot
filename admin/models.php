<?php
/**
 * EDUC AI TalkBot - Models Management
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication
checkSetup();
requireAuth();

// Handle API test
$message = '';
$messageType = '';

if ($_POST && isset($_POST['action']) && $_POST['action'] === 'test_api') {
    try {
        $result = testAPIConnection();
        $message = $result['message'];
        $messageType = $result['type'];
    } catch (Exception $e) {
        $message = 'API test failed: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Get available models from GWDG SAIA
$availableModels = [];
try {
    $availableModels = getAvailableModels();
} catch (Exception $e) {
    $message = 'Failed to fetch models: ' . $e->getMessage();
    $messageType = 'warning';
}

// GWDG SAIA model information from the documentation
$gwdgModels = [
    [
        'id' => 'meta-llama-3.1-8b-instruct',
        'name' => 'Meta Llama 3.1 8B Instruct',
        'capabilities' => ['text'],
        'description' => 'High-performance instruction-following model'
    ],
    [
        'id' => 'meta-llama-3.1-8b-rag',
        'name' => 'Meta Llama 3.1 8B RAG',
        'capabilities' => ['text', 'arcana'],
        'description' => 'Optimized for Retrieval-Augmented Generation'
    ],
    [
        'id' => 'llama-3.1-sauerkrautlm-70b-instruct',
        'name' => 'Llama 3.1 SauerkrautLM 70B',
        'capabilities' => ['text', 'arcana'],
        'description' => 'German-optimized large language model'
    ],
    [
        'id' => 'llama-3.3-70b-instruct',
        'name' => 'Llama 3.3 70B Instruct',
        'capabilities' => ['text'],
        'description' => 'Latest Llama model with improved performance'
    ],
    [
        'id' => 'gemma-3-27b-it',
        'name' => 'Gemma 3 27B IT',
        'capabilities' => ['text', 'image'],
        'description' => 'Google\'s multimodal model for text and images'
    ],
    [
        'id' => 'mistral-large-instruct',
        'name' => 'Mistral Large Instruct',
        'capabilities' => ['text'],
        'description' => 'High-quality instruction-following model'
    ],
    [
        'id' => 'qwen3-32b',
        'name' => 'Qwen 3 32B',
        'capabilities' => ['text'],
        'description' => 'Alibaba\'s multilingual model'
    ],
    [
        'id' => 'qwen3-235b-a22b',
        'name' => 'Qwen 3 235B A22B',
        'capabilities' => ['text'],
        'description' => 'Large-scale multilingual model'
    ],
    [
        'id' => 'qwen2.5-coder-32b-instruct',
        'name' => 'Qwen 2.5 Coder 32B',
        'capabilities' => ['text', 'code'],
        'description' => 'Specialized for code generation and programming'
    ],
    [
        'id' => 'codestral-22b',
        'name' => 'Codestral 22B',
        'capabilities' => ['text', 'code'],
        'description' => 'Mistral\'s code-specialized model'
    ],
    [
        'id' => 'internvl2.5-8b',
        'name' => 'InternVL 2.5 8B',
        'capabilities' => ['text', 'image'],
        'description' => 'Vision-language model for multimodal tasks'
    ],
    [
        'id' => 'qwen-2.5-vl-72b-instruct',
        'name' => 'Qwen 2.5 VL 72B',
        'capabilities' => ['text', 'image'],
        'description' => 'Large vision-language model'
    ],
    [
        'id' => 'qwq-32b',
        'name' => 'QwQ 32B',
        'capabilities' => ['reasoning'],
        'description' => 'Specialized for complex reasoning tasks'
    ],
    [
        'id' => 'deepseek-r1',
        'name' => 'DeepSeek R1',
        'capabilities' => ['reasoning'],
        'description' => 'Advanced reasoning and problem-solving model'
    ],
    [
        'id' => 'e5-mistral-7b-instruct',
        'name' => 'E5 Mistral 7B Instruct',
        'capabilities' => ['embeddings'],
        'description' => 'Embedding model for vector generation'
    ]
];

// Get current model setting
$currentModel = getCurrentSetting('model');

// Page configuration
$pageTitle = 'AI Models';
$pageIcon = 'bi bi-cpu';

// Include header
include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'x-circle' : 'info-circle') ?>"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="bi bi-cpu"></i> Available GWDG SAIA Models
                </h5>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="test_api">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-clockwise"></i> Test API Connection
                        </button>
                    </form>
                    <a href="https://docs.hpc.gwdg.de/services/saia/index.html" target="_blank" class="btn btn-outline-info btn-sm ms-2">
                        <i class="bi bi-book"></i> Documentation
                    </a>
                </div>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>GWDG SAIA API:</strong> These models are available through the GWDG SAIA platform. 
                Make sure you have configured your API key in the Settings page.
            </div>
            
            <?php if (empty($availableModels) && !$message): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    Unable to fetch live models from API. Showing available models from documentation.
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Model Name</th>
                            <th>Model ID</th>
                            <th>Capabilities</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gwdgModels as $model): ?>
                            <tr class="<?= $currentModel === $model['id'] ? 'table-primary' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($model['name']) ?></strong>
                                    <?php if ($currentModel === $model['id']): ?>
                                        <span class="badge bg-primary ms-2">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="text-muted"><?= htmlspecialchars($model['id']) ?></code>
                                </td>
                                <td>
                                    <?php foreach ($model['capabilities'] as $capability): ?>
                                        <?php
                                        $badgeClass = match($capability) {
                                            'text' => 'bg-primary',
                                            'image' => 'bg-success',
                                            'code' => 'bg-info',
                                            'reasoning' => 'bg-warning',
                                            'embeddings' => 'bg-secondary',
                                            'arcana' => 'bg-dark',
                                            default => 'bg-light text-dark'
                                        };
                                        ?>
                                        <span class="badge <?= $badgeClass ?> me-1"><?= htmlspecialchars($capability) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($model['description']) ?></td>
                                <td>
                                    <?php if (!empty($availableModels)): ?>
                                        <?php 
                                        $available = false;
                                        foreach ($availableModels as $apiModel) {
                                            if ($apiModel['id'] === $model['id']) {
                                                $available = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($available): ?>
                                            <span class="badge bg-success">Available</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Check API</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Unknown</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (!empty($availableModels)): ?>
                <div class="mt-4">
                    <h6><i class="bi bi-cloud"></i> Live API Models</h6>
                    <p class="text-muted">Models currently available through your API connection:</p>
                    <div class="row">
                        <?php foreach ($availableModels as $model): ?>
                            <div class="col-md-6 mb-2">
                                <div class="p-2 border rounded">
                                    <code><?= htmlspecialchars($model['id']) ?></code>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Model Categories -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-tags"></i> Model Categories
            </h5>
            
            <div class="mb-3">
                <h6>By Capability</h6>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-primary">Text Generation</span>
                    <span class="badge bg-success">Vision</span>
                    <span class="badge bg-info">Code</span>
                    <span class="badge bg-warning">Reasoning</span>
                    <span class="badge bg-secondary">Embeddings</span>
                    <span class="badge bg-dark">RAG Support</span>
                </div>
            </div>
            
            <div class="mb-3">
                <h6>Model Sizes</h6>
                <ul class="list-unstyled">
                    <li><small class="text-muted">7B-8B parameters:</small> Fast, efficient</li>
                    <li><small class="text-muted">22B-32B parameters:</small> Balanced performance</li>
                    <li><small class="text-muted">70B+ parameters:</small> High quality</li>
                </ul>
            </div>
        </div>
        
        <!-- API Information -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-cloud"></i> API Information
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td>Endpoint:</td>
                    <td><small class="text-muted"><?= htmlspecialchars(getenv('AI_API_ENDPOINT') ?: 'Not configured') ?></small></td>
                </tr>
                <tr>
                    <td>API Key:</td>
                    <td>
                        <?php if (getenv('AI_API_KEY')): ?>
                            <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Missing</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Models Endpoint:</td>
                    <td><small class="text-muted"><?= htmlspecialchars(getenv('MODELS_API_ENDPOINT') ?: 'Default') ?></small></td>
                </tr>
                <tr>
                    <td>Current Model:</td>
                    <td>
                        <?php if ($currentModel): ?>
                            <code><?= htmlspecialchars($currentModel) ?></code>
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <div class="mt-3">
                <a href="settings.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-gear"></i> Configure API Settings
                </a>
            </div>
        </div>
        
        <!-- Usage Guidelines -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-lightbulb"></i> Usage Guidelines
            </h5>
            
            <div class="small">
                <h6>Text Models</h6>
                <p>Best for chat, Q&A, content generation, and general conversation.</p>
                
                <h6>Vision Models</h6>
                <p>Can process both text and images. Useful for image analysis and multimodal tasks.</p>
                
                <h6>Code Models</h6>
                <p>Specialized for programming tasks, code review, and technical documentation.</p>
                
                <h6>Reasoning Models</h6>
                <p>Optimized for complex problem-solving, mathematical reasoning, and logical tasks.</p>
                
                <h6>RAG Models</h6>
                <p>Work with Arcanas (knowledge bases) for document-based question answering.</p>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh model status every 30 seconds if on this page
setInterval(function() {
    // Could implement live status checking here
}, 30000);
</script>

<?php
include __DIR__ . '/includes/footer.php';
?> 