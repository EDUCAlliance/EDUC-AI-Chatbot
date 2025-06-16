<?php
/**
 * EDUC AI TalkBot - Settings Page
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication and setup
checkSetup();
requireAuth();

// Initialize components
$db = \EDUC\Database\Database::getInstance();
$settings = $db->getAllSettings();

// Initialize LLM client for model fetching
$llmClient = null;
$availableModels = [];
$llmClientError = null;

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
    \EDUC\Utils\Logger::error('Failed to initialize LLM client in settings', ['error' => $e->getMessage()]);
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
            case 'save_general':
                handleGeneralSettings($db, $_POST);
                $message = 'General settings saved successfully!';
                $messageType = 'success';
                break;
                
            case 'save_ai':
                handleAISettings($db, $_POST);
                $message = 'AI settings saved successfully!';
                $messageType = 'success';
                break;
                
            case 'save_onboarding':
                handleOnboardingSettings($db, $_POST);
                $message = 'Onboarding settings saved successfully!';
                $messageType = 'success';
                break;
                
            case 'test_api':
                $result = testAPIConnection();
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
        // Refresh settings after changes
        $settings = $db->getAllSettings();
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Settings page error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

/**
 * Handle general settings
 */
function handleGeneralSettings(\EDUC\Database\Database $db, array $postData): void {
    $generalSettings = [
        'bot_mention',
        'debug_mode',
        'welcome_message',
        'timezone'
    ];
    
    foreach ($generalSettings as $setting) {
        if ($setting === 'debug_mode') {
            $value = isset($postData[$setting]) ? 'true' : 'false';
            $db->setSetting($setting, $value);
        } elseif (isset($postData[$setting])) {
            $db->setSetting($setting, $postData[$setting]);
        }
    }
    
    \EDUC\Utils\Logger::info('General settings updated');
}

/**
 * Handle AI settings
 */
function handleAISettings(\EDUC\Database\Database $db, array $postData): void {
    $aiSettings = [
        'system_prompt',
        'model',
        'temperature',
        'max_tokens',
        'top_p'
    ];
    
    foreach ($aiSettings as $setting) {
        if (isset($postData[$setting])) {
            $db->setSetting($setting, $postData[$setting]);
        }
    }
    
    \EDUC\Utils\Logger::info('AI settings updated');
}

/**
 * Handle onboarding settings
 */
function handleOnboardingSettings(\EDUC\Database\Database $db, array $postData): void {
    // Handle user onboarding questions
    if (isset($postData['user_onboarding_questions'])) {
        $questions = array_filter($postData['user_onboarding_questions']);
        $db->setSetting('user_onboarding_questions', json_encode($questions));
    }
    
    // Handle group onboarding questions
    if (isset($postData['group_onboarding_questions'])) {
        $questions = array_filter($postData['group_onboarding_questions']);
        $db->setSetting('group_onboarding_questions', json_encode($questions));
    }
    
    \EDUC\Utils\Logger::info('Onboarding settings updated');
}

/**
 * Test API connection
 */
function testAPIConnection(): array {
    try {
        $apiKey = \EDUC\Core\Environment::get('AI_API_KEY');
        $apiEndpoint = \EDUC\Core\Environment::get('AI_API_ENDPOINT');
        
        if (!$apiKey || !$apiEndpoint) {
            return [
                'message' => 'Missing AI_API_KEY or AI_API_ENDPOINT environment variables',
                'type' => 'error'
            ];
        }
        
        $llmClient = new \EDUC\API\LLMClient(
            $apiKey,
            $apiEndpoint,
            \EDUC\Core\Environment::get('EMBEDDING_API_ENDPOINT'),
            \EDUC\Core\Environment::get('MODELS_API_ENDPOINT')
        );
        
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

$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

// Page configuration
$pageTitle = 'Settings';
$pageIcon = 'bi bi-gear';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Settings Content -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Settings Tabs -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <!-- Tab Navigation -->
            <ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button">
                        <i class="bi bi-gear"></i> General
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="ai-tab" data-bs-toggle="pill" data-bs-target="#ai" type="button">
                        <i class="bi bi-cpu"></i> AI Configuration
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="onboarding-tab" data-bs-toggle="pill" data-bs-target="#onboarding" type="button">
                        <i class="bi bi-chat-dots"></i> Onboarding
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="environment-tab" data-bs-toggle="pill" data-bs-target="#environment" type="button">
                        <i class="bi bi-shield-check"></i> Environment
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="settingsTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_general">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="bot_mention" class="form-label">Bot Mention Name</label>
                                    <input type="text" class="form-control" id="bot_mention" name="bot_mention" 
                                           value="<?= htmlspecialchars($settings['bot_mention']['value'] ?? 'AI Assistant') ?>" required>
                                    <div class="form-text">Name users will use to mention the bot in Nextcloud Talk</div>
                                </div>

                                <div class="mb-3">
                                    <label for="welcome_message" class="form-label">Welcome Message</label>
                                    <textarea class="form-control" id="welcome_message" name="welcome_message" rows="3"><?= htmlspecialchars($settings['welcome_message']['value'] ?? '') ?></textarea>
                                    <div class="form-text">Message sent when starting new conversations</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="timezone" class="form-label">Timezone</label>
                                    <select class="form-select" id="timezone" name="timezone">
                                        <?php
                                        $currentTimezone = $settings['timezone']['value'] ?? 'Europe/Berlin';
                                        $timezones = [
                                            'Europe/Berlin' => 'Europe/Berlin (CET/CEST)',
                                            'UTC' => 'UTC',
                                            'America/New_York' => 'America/New_York (EST/EDT)',
                                            'America/Los_Angeles' => 'America/Los_Angeles (PST/PDT)',
                                            'Asia/Tokyo' => 'Asia/Tokyo (JST)',
                                            'Asia/Shanghai' => 'Asia/Shanghai (CST)'
                                        ];
                                        ?>
                                        <?php foreach ($timezones as $tz => $display): ?>
                                            <option value="<?= $tz ?>" <?= $currentTimezone === $tz ? 'selected' : '' ?>>
                                                <?= $display ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" value="true"
                                               <?= ($settings['debug_mode']['value'] ?? '') === 'true' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="debug_mode">
                                            Enable Debug Mode
                                        </label>
                                    </div>
                                    <div class="form-text">Show detailed information in bot responses and logs</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- AI Configuration Tab -->
                <div class="tab-pane fade" id="ai" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_ai">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="model" class="form-label">AI Model</label>
                                    <select class="form-select" id="model" name="model" required>
                                        <?php if ($llmClientError): ?>
                                            <option value="" selected disabled>
                                                API Configuration Required
                                            </option>
                                            <!-- Fallback models for configuration -->
                                            <option value="meta-llama-3.1-8b-instruct" <?= ($settings['model']['value'] ?? '') === 'meta-llama-3.1-8b-instruct' ? 'selected' : '' ?>>
                                                Meta Llama 3.1 8B Instruct (fallback)
                                            </option>
                                            <option value="llama-3.3-70b-instruct" <?= ($settings['model']['value'] ?? '') === 'llama-3.3-70b-instruct' ? 'selected' : '' ?>>
                                                Llama 3.3 70B Instruct (fallback)
                                            </option>
                                        <?php elseif (empty($availableModels)): ?>
                                            <option value="" selected disabled>
                                                No models available
                                            </option>
                                        <?php else: ?>
                                            <?php foreach ($availableModels as $model): ?>
                                                <option value="<?= htmlspecialchars($model['id']) ?>" 
                                                        <?= ($settings['model']['value'] ?? '') === $model['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($model['name']) ?>
                                                    <?php if (!empty($model['capabilities'])): ?>
                                                        (<?= implode(', ', $model['capabilities']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="form-text">
                                        <?php if ($llmClientError): ?>
                                            <span class="text-warning">⚠️ Configure API settings to load available models</span>
                                        <?php else: ?>
                                            Select the AI model to use for responses
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="temperature" class="form-label">Temperature</label>
                                    <input type="range" class="form-range" id="temperature" name="temperature" 
                                           min="0" max="2" step="0.1" 
                                           value="<?= $settings['temperature']['value'] ?? '0.7' ?>"
                                           oninput="this.nextElementSibling.value = this.value">
                                    <output><?= $settings['temperature']['value'] ?? '0.7' ?></output>
                                    <div class="form-text">Controls randomness: 0 = focused, 2 = creative</div>
                                </div>

                                <div class="mb-3">
                                    <label for="max_tokens" class="form-label">Max Tokens</label>
                                    <input type="number" class="form-control" id="max_tokens" name="max_tokens" 
                                           value="<?= $settings['max_tokens']['value'] ?? '2048' ?>" min="1" max="8192">
                                    <div class="form-text">Maximum length of generated responses</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="top_p" class="form-label">Top P</label>
                                    <input type="range" class="form-range" id="top_p" name="top_p" 
                                           min="0" max="1" step="0.05" 
                                           value="<?= $settings['top_p']['value'] ?? '0.9' ?>"
                                           oninput="this.nextElementSibling.value = this.value">
                                    <output><?= $settings['top_p']['value'] ?? '0.9' ?></output>
                                    <div class="form-text">Nucleus sampling: 0.1 = focused, 1.0 = diverse</div>
                                </div>

                                <div class="mb-3">
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="test_api">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <button type="submit" class="btn btn-outline-primary">
                                            <i class="bi bi-wifi"></i> Test API Connection
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="system_prompt" class="form-label">System Prompt</label>
                            <textarea class="form-control" id="system_prompt" name="system_prompt" rows="6" required><?= htmlspecialchars($settings['system_prompt']['value'] ?? '') ?></textarea>
                            <div class="form-text">Instructions that define the AI's behavior and personality</div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save AI Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Onboarding Tab -->
                <div class="tab-pane fade" id="onboarding" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_onboarding">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>User Onboarding Questions</h5>
                                <p class="text-muted">Questions asked when a user starts a private conversation</p>
                                <div id="user-questions">
                                    <?php 
                                    $userQuestions = json_decode($settings['user_onboarding_questions']['value'] ?? '[]', true);
                                    if ($userQuestions) {
                                        foreach ($userQuestions as $i => $question): 
                                    ?>
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" name="user_onboarding_questions[]" 
                                                   value="<?= htmlspecialchars($question) ?>">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addUserQuestion()">
                                    <i class="bi bi-plus"></i> Add Question
                                </button>
                            </div>

                            <div class="col-md-6">
                                <h5>Group Onboarding Questions</h5>
                                <p class="text-muted">Questions asked when the bot is added to a group</p>
                                <div id="group-questions">
                                    <?php 
                                    $groupQuestions = json_decode($settings['group_onboarding_questions']['value'] ?? '[]', true);
                                    if ($groupQuestions) {
                                        foreach ($groupQuestions as $i => $question): 
                                    ?>
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" name="group_onboarding_questions[]" 
                                                   value="<?= htmlspecialchars($question) ?>">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGroupQuestion()">
                                    <i class="bi bi-plus"></i> Add Question
                                </button>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save Onboarding Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Environment Tab -->
                <div class="tab-pane fade" id="environment" role="tabpanel">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Environment Variables</strong><br>
                        These settings are configured at the system level and cannot be changed here.
                        Contact your administrator to modify these values.
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Variable</th>
                                    <th>Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $envVars = getEnvironmentVariables();
                                foreach ($envVars as $var => $value): 
                                ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($var) ?></code></td>
                                        <td>
                                            <?php if (strpos($value, '[SET') === 0): ?>
                                                <span class="text-muted"><?= htmlspecialchars($value) ?></span>
                                            <?php elseif ($value === 'NOT SET'): ?>
                                                <span class="text-danger">Not Set</span>
                                            <?php else: ?>
                                                <code><?= htmlspecialchars($value) ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($value === 'NOT SET'): ?>
                                                <span class="badge bg-danger">Missing</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">OK</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
</script>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?>



$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

// Page configuration
$pageTitle = 'Settings';
$pageIcon = 'bi bi-gear';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Settings Content -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Settings Tabs -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <!-- Tab Navigation -->
            <ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button">
                        <i class="bi bi-gear"></i> General
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="ai-tab" data-bs-toggle="pill" data-bs-target="#ai" type="button">
                        <i class="bi bi-cpu"></i> AI Configuration
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="onboarding-tab" data-bs-toggle="pill" data-bs-target="#onboarding" type="button">
                        <i class="bi bi-chat-dots"></i> Onboarding
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="environment-tab" data-bs-toggle="pill" data-bs-target="#environment" type="button">
                        <i class="bi bi-shield-check"></i> Environment
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="settingsTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_general">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="bot_mention" class="form-label">Bot Mention Name</label>
                                    <input type="text" class="form-control" id="bot_mention" name="bot_mention" 
                                           value="<?= htmlspecialchars($settings['bot_mention']['value'] ?? 'AI Assistant') ?>" required>
                                    <div class="form-text">Name users will use to mention the bot in Nextcloud Talk</div>
                                </div>

                                <div class="mb-3">
                                    <label for="welcome_message" class="form-label">Welcome Message</label>
                                    <textarea class="form-control" id="welcome_message" name="welcome_message" rows="3"><?= htmlspecialchars($settings['welcome_message']['value'] ?? '') ?></textarea>
                                    <div class="form-text">Message sent when starting new conversations</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="timezone" class="form-label">Timezone</label>
                                    <select class="form-select" id="timezone" name="timezone">
                                        <?php
                                        $currentTimezone = $settings['timezone']['value'] ?? 'Europe/Berlin';
                                        $timezones = [
                                            'Europe/Berlin' => 'Europe/Berlin (CET/CEST)',
                                            'UTC' => 'UTC',
                                            'America/New_York' => 'America/New_York (EST/EDT)',
                                            'America/Los_Angeles' => 'America/Los_Angeles (PST/PDT)',
                                            'Asia/Tokyo' => 'Asia/Tokyo (JST)',
                                            'Asia/Shanghai' => 'Asia/Shanghai (CST)'
                                        ];
                                        ?>
                                        <?php foreach ($timezones as $tz => $display): ?>
                                            <option value="<?= $tz ?>" <?= $currentTimezone === $tz ? 'selected' : '' ?>>
                                                <?= $display ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" value="true"
                                               <?= ($settings['debug_mode']['value'] ?? '') === 'true' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="debug_mode">
                                            Enable Debug Mode
                                        </label>
                                    </div>
                                    <div class="form-text">Show detailed information in bot responses and logs</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- AI Configuration Tab -->
                <div class="tab-pane fade" id="ai" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_ai">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="model" class="form-label">AI Model</label>
                                    <select class="form-select" id="model" name="model" required>
                                        <?php if ($llmClientError): ?>
                                            <option value="" selected disabled>
                                                API Configuration Required
                                            </option>
                                            <!-- Fallback models for configuration -->
                                            <option value="meta-llama-3.1-8b-instruct" <?= ($settings['model']['value'] ?? '') === 'meta-llama-3.1-8b-instruct' ? 'selected' : '' ?>>
                                                Meta Llama 3.1 8B Instruct (fallback)
                                            </option>
                                            <option value="llama-3.3-70b-instruct" <?= ($settings['model']['value'] ?? '') === 'llama-3.3-70b-instruct' ? 'selected' : '' ?>>
                                                Llama 3.3 70B Instruct (fallback)
                                            </option>
                                        <?php elseif (empty($availableModels)): ?>
                                            <option value="" selected disabled>
                                                No models available
                                            </option>
                                        <?php else: ?>
                                            <?php foreach ($availableModels as $model): ?>
                                                <option value="<?= htmlspecialchars($model['id']) ?>" 
                                                        <?= ($settings['model']['value'] ?? '') === $model['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($model['name']) ?>
                                                    <?php if (!empty($model['capabilities'])): ?>
                                                        (<?= implode(', ', $model['capabilities']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="form-text">
                                        <?php if ($llmClientError): ?>
                                            <span class="text-warning">⚠️ Configure API settings to load available models</span>
                                        <?php else: ?>
                                            Select the AI model to use for responses
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="temperature" class="form-label">Temperature</label>
                                    <input type="range" class="form-range" id="temperature" name="temperature" 
                                           min="0" max="2" step="0.1" 
                                           value="<?= $settings['temperature']['value'] ?? '0.7' ?>"
                                           oninput="this.nextElementSibling.value = this.value">
                                    <output><?= $settings['temperature']['value'] ?? '0.7' ?></output>
                                    <div class="form-text">Controls randomness: 0 = focused, 2 = creative</div>
                                </div>

                                <div class="mb-3">
                                    <label for="max_tokens" class="form-label">Max Tokens</label>
                                    <input type="number" class="form-control" id="max_tokens" name="max_tokens" 
                                           value="<?= $settings['max_tokens']['value'] ?? '2048' ?>" min="1" max="8192">
                                    <div class="form-text">Maximum length of generated responses</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="top_p" class="form-label">Top P</label>
                                    <input type="range" class="form-range" id="top_p" name="top_p" 
                                           min="0" max="1" step="0.05" 
                                           value="<?= $settings['top_p']['value'] ?? '0.9' ?>"
                                           oninput="this.nextElementSibling.value = this.value">
                                    <output><?= $settings['top_p']['value'] ?? '0.9' ?></output>
                                    <div class="form-text">Nucleus sampling: 0.1 = focused, 1.0 = diverse</div>
                                </div>

                                <div class="mb-3">
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="test_api">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <button type="submit" class="btn btn-outline-primary">
                                            <i class="bi bi-wifi"></i> Test API Connection
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="system_prompt" class="form-label">System Prompt</label>
                            <textarea class="form-control" id="system_prompt" name="system_prompt" rows="6" required><?= htmlspecialchars($settings['system_prompt']['value'] ?? '') ?></textarea>
                            <div class="form-text">Instructions that define the AI's behavior and personality</div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save AI Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Onboarding Tab -->
                <div class="tab-pane fade" id="onboarding" role="tabpanel">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="save_onboarding">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>User Onboarding Questions</h5>
                                <p class="text-muted">Questions asked when a user starts a private conversation</p>
                                <div id="user-questions">
                                    <?php 
                                    $userQuestions = json_decode($settings['user_onboarding_questions']['value'] ?? '[]', true);
                                    if ($userQuestions) {
                                        foreach ($userQuestions as $i => $question): 
                                    ?>
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" name="user_onboarding_questions[]" 
                                                   value="<?= htmlspecialchars($question) ?>">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addUserQuestion()">
                                    <i class="bi bi-plus"></i> Add Question
                                </button>
                            </div>

                            <div class="col-md-6">
                                <h5>Group Onboarding Questions</h5>
                                <p class="text-muted">Questions asked when the bot is added to a group</p>
                                <div id="group-questions">
                                    <?php 
                                    $groupQuestions = json_decode($settings['group_onboarding_questions']['value'] ?? '[]', true);
                                    if ($groupQuestions) {
                                        foreach ($groupQuestions as $i => $question): 
                                    ?>
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" name="group_onboarding_questions[]" 
                                                   value="<?= htmlspecialchars($question) ?>">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeQuestion(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGroupQuestion()">
                                    <i class="bi bi-plus"></i> Add Question
                                </button>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save Onboarding Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Environment Tab -->
                <div class="tab-pane fade" id="environment" role="tabpanel">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Environment Variables</strong><br>
                        These settings are configured at the system level and cannot be changed here.
                        Contact your administrator to modify these values.
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Variable</th>
                                    <th>Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $envVars = getEnvironmentVariables();
                                foreach ($envVars as $var => $value): 
                                ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($var) ?></code></td>
                                        <td>
                                            <?php if (strpos($value, '[SET') === 0): ?>
                                                <span class="text-muted"><?= htmlspecialchars($value) ?></span>
                                            <?php elseif ($value === 'NOT SET'): ?>
                                                <span class="text-danger">Not Set</span>
                                            <?php else: ?>
                                                <code><?= htmlspecialchars($value) ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($value === 'NOT SET'): ?>
                                                <span class="badge bg-danger">Missing</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">OK</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
</script>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?> 