<?php
session_start();

// Update path to the renamed config file in the root directory
require_once __DIR__ . '/admin_config.php'; 

// Autoloader is included via admin_config.php

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
    // $rootDir is defined in admin_config.php
    $db = new Database(); // Uses DB_PATH from .env loaded by admin_config.php
    $configRepo = new ConfigRepository($db);

    // Try loading initial config from JSON if table is empty
    // Use $rootDir from the included config file
    $initialConfigPath = $rootDir . '/llm_config.json'; 
    if (file_exists($initialConfigPath)) {
       $configRepo->loadInitialConfigFromJson($initialConfigPath);
       // Consider adding unlink($initialConfigPath); here after confirmation it works
    } 

} catch (Exception $e) {
    $errorMessage = "Database Initialization Error: " . $e->getMessage();
    $configRepo = null;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    try {
        $correctPassword = getAdminPassword(); // Function from admin_config.php
        if (hash_equals($correctPassword, $_POST['password'])) {
            $_SESSION['admin_logged_in'] = true;
            // Redirect to the current script name
            header('Location: admin.php'); 
            exit;
        } else {
            $errorMessage = 'Invalid password.';
        }
    } catch (Exception $e) {
        $errorMessage = 'Error: ' . $e->getMessage();
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    // Redirect to the current script name
    header('Location: admin.php');
    exit;
}

$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Logic for logged-in users
if ($isLoggedIn && $configRepo) {
    // Handle saving configuration
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
            $errorMessage = 'Failed to save configuration.';
        }
        // Reload config after saving to show updated values immediately
        $currentConfig = $configRepo->getAllConfig(); 
    } else {
      // Load current configuration for display if not saving
      $currentConfig = $configRepo->getAllConfig();
    }

    // Ensure default keys exist even if not in DB yet
    $currentConfig['systemPrompt'] = $currentConfig['systemPrompt'] ?? '';
    $currentConfig['model'] = $currentConfig['model'] ?? '';
    $currentConfig['botMention'] = $currentConfig['botMention'] ?? '';

} elseif ($isLoggedIn && !$configRepo) {
    $errorMessage = "Database connection failed. Cannot load or save configuration.";
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
    </style>
</head>
<body>
    <div class="container">
        <h1>EDUC AI TalkBot Admin Panel</h1>

        <?php if (!empty($errorMessage)): ?>
            <p class="error"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php endif; ?>
        <?php if (!empty($successMessage)): ?>
            <p class="success"><?php echo htmlspecialchars($successMessage); ?></p>
        <?php endif; ?>

        <?php if (!$isLoggedIn): ?>
            <h2>Login</h2>
            <form method="post">
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <input type="submit" value="Login">
            </form>
        <?php elseif ($configRepo): ?>
            <div class="logout-link"><a href="?logout=1">Logout</a></div>
            <h2>Configuration</h2>
            <form method="post">
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
             <div class="logout-link"><a href="?logout=1">Logout</a></div>
             <p class="error">Could not connect to the database to load configuration.</p>
        <?php endif; ?>
    </div>
</body>
</html> 