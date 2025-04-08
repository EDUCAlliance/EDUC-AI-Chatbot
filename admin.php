<?php
// Remove temporary error reporting if present
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// session_start(); // REMOVED SESSION START

require_once __DIR__ . '/admin_config.php';

use Educ\Talkbot\Core\Database;
use Educ\Talkbot\Core\ConfigRepository;

$errorMessage = '';
$successMessage = '';
$configRepo = null;
$currentConfig = [
    'systemPrompt' => '',
    'model' => '',
    'botMention' => ''
];

try {
    // $projectRoot is defined in admin_config.php
    $db = new Database(); 
    $configRepo = new ConfigRepository($db);

    // Try loading initial config from JSON if table is empty
    $initialConfigPath = $projectRoot . '/llm_config.json'; 
    if (file_exists($initialConfigPath)) {
       $configRepo->loadInitialConfigFromJson($initialConfigPath);
       // unlink($initialConfigPath); // Consider unlinking after success
    } 

} catch (Exception $e) {
    // Log the full error for server-side debugging
    error_log("Admin Panel DB/Config Init Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    // Set a user-friendly error message
    $errorMessage = "Database Initialization Error: Could not connect or initialize configuration. Check server logs.";
    $configRepo = null;
}

// REMOVED LOGIN HANDLING BLOCK

// REMOVED LOGOUT HANDLING BLOCK

// $isLoggedIn = true; // REMOVED - No longer tracking login state

// Logic now runs if config repository loaded successfully
if ($configRepo) { 
    // Handle saving configuration (POST request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
        $systemPrompt = $_POST['system_prompt'] ?? '';
        $model = $_POST['model'] ?? '';
        $botMention = $_POST['bot_mention'] ?? '';

        $saved = true;
        $saved &= $configRepo->setConfig('systemPrompt', $systemPrompt);
        $saved &= $configRepo->setConfig('model', $model);
        $saved &= $configRepo->setConfig('botMention', $botMention);

        if ($saved) {
            $successMessage = 'Configuration saved successfully.';
        } else {
            $errorMessage = 'Failed to save one or more configuration values. Check server logs.';
        }
        // Reload config after saving to show updated values immediately
        $currentConfig = $configRepo->getAllConfig(); 
    } else {
        // Load current configuration for display (GET request)
        $currentConfig = $configRepo->getAllConfig();
    }

    // Ensure default keys exist even if not in DB yet
    $currentConfig['systemPrompt'] = $currentConfig['systemPrompt'] ?? 'Default system prompt not found in DB.';
    $currentConfig['model'] = $currentConfig['model'] ?? 'Default model not found in DB.';
    $currentConfig['botMention'] = $currentConfig['botMention'] ?? 'Default bot mention not found in DB.';

} elseif (empty($errorMessage)) { // Only set this if no DB init error occurred earlier
    $errorMessage = "Configuration could not be loaded. Config Repository is not available.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDUC AI TalkBot Admin</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="password"], textarea { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 3px; }
        textarea { min-height: 150px; }
        input[type="submit"] { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 1em; }
        input[type="submit"]:hover { background-color: #0056b3; }
        .error { color: red; margin-bottom: 10px; padding: 10px; border: 1px solid red; background-color: #ffecec; border-radius: 3px; }
        .success { color: green; margin-bottom: 10px; padding: 10px; border: 1px solid green; background-color: #e8fadf; border-radius: 3px; }
        h1, h2 { text-align: center; color: #333; }
        h1 { margin-bottom: 30px; }
        h2 { margin-bottom: 20px; margin-top: 0; }
        label { font-weight: bold; color: #555; }
        .logout-link { text-align: right; margin-bottom: 15px; }
        .logout-link a { color: #007bff; text-decoration: none; }
        .logout-link a:hover { text-decoration: underline; }
        form { background-color: #f9f9f9; padding: 20px; border-radius: 5px; border: 1px solid #eee; }
        .security-warning { color: #d9534f; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; margin-bottom: 20px; border-radius: 4px; font-weight: bold; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>EDUC AI TalkBot Admin Panel</h1>
        
        <div class="security-warning">
            Warning: This admin panel is currently unprotected. Please secure access via web server configuration (e.g., Basic Auth, IP restriction) or Cloudron access controls.
        </div>

        <?php if (!empty($errorMessage)): ?>
            <p class="error"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php endif; ?>
        <?php if (!empty($successMessage)): ?>
            <p class="success"><?php echo htmlspecialchars($successMessage); ?></p>
        <?php endif; ?>

        <?php // REMOVED LOGIN CHECK - Show form if configRepo is available ?>
        <?php if ($configRepo): ?>
            <?php // REMOVED LOGOUT LINK ?> 
            <h2>Configuration</h2>
            <form method="post" action="admin.php"> <?php // Ensure form posts to itself ?>
                <div class="form-group">
                    <label for="system_prompt">System Prompt:</label>
                    <textarea id="system_prompt" name="system_prompt" required><?php echo htmlspecialchars($currentConfig['systemPrompt']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="model">LLM Model:</label>
                    <input type="text" id="model" name="model" value="<?php echo htmlspecialchars($currentConfig['model']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="bot_mention">Bot Mention Name:</label>
                    <input type="text" id="bot_mention" name="bot_mention" value="<?php echo htmlspecialchars($currentConfig['botMention']); ?>" required>
                </div>
                <input type="submit" name="save_config" value="Save Configuration">
            </form>
        <?php else: ?>
             <?php // Error message is already shown above if $configRepo failed to load ?>
             <p>Configuration form cannot be displayed.</p>
        <?php endif; ?>
    </div>
</body>
</html> 