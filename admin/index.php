<?php
session_start();

require_once __DIR__ . '/config.php';
// NOTE: Assuming you have a PSR-4 autoloader set up (e.g., via Composer)
// If not, you'll need manual require_once statements for Database and ConfigRepository
require_once $rootDir . '/src/Core/Database.php';
require_once $rootDir . '/src/Core/ConfigRepository.php';

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
    $db = new Database(); // Uses DB_PATH from .env
    $configRepo = new ConfigRepository($db);

    // Try loading initial config from JSON if table is empty
    $initialConfigPath = $rootDir . '/llm_config.json'; // Use root dir path
    $configRepo->loadInitialConfigFromJson($initialConfigPath);

} catch (Exception $e) {
    $errorMessage = "Database Initialization Error: " . $e->getMessage();
    // Prevent further operations if DB connection fails
    $configRepo = null;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    try {
        $correctPassword = getAdminPassword();
        if (hash_equals($correctPassword, $_POST['password'])) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: index.php'); // Redirect to avoid form resubmission
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
    header('Location: index.php');
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
    }

    // Load current configuration for display
    $currentConfig = $configRepo->getAllConfig();
    // Ensure default keys exist even if not in DB yet
    $currentConfig['systemPrompt'] = $currentConfig['systemPrompt'] ?? '';
    $currentConfig['model'] = $currentConfig['model'] ?? '';
    $currentConfig['botMention'] = $currentConfig['botMention'] ?? '';

} elseif ($isLoggedIn && !$configRepo) {
    // Handle case where user is logged in but DB failed
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
        input[type="text"], input[type="password"], textarea { width: 100%; padding: 8px; box-sizing: border-box; }
        input[type="submit"] { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        input[type="submit"]:hover { background-color: #0056b3; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; }
        h1, h2 { text-align: center; }
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
        <?php elseif ($configRepo): // Only show config form if DB connection is okay ?>
            <p style="text-align: right;"><a href="?logout=1">Logout</a></p>
            <h2>Configuration</h2>
            <form method="post">
                <div class="form-group">
                    <label for="system_prompt">System Prompt:</label>
                    <textarea id="system_prompt" name="system_prompt" rows="10" required><?php echo htmlspecialchars($currentConfig['systemPrompt']); ?></textarea>
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
        <?php else: // Logged in but DB error ?>
             <p style="text-align: right;"><a href="?logout=1">Logout</a></p>
             <p>Could not connect to the database to load configuration.</p>
        <?php endif; ?>
    </div>
</body>
</html> 