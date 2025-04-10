<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_save_path('/app/code/public');
session_start();

// Load environment variables specifically for admin
require_once(__DIR__ . '/includes/env_loader.php');
loadAdminEnv(); // Use the function from env_loader.php

// Include Core classes
require_once(__DIR__ . '/../src/Core/Environment.php'); // Load Environment class

// Include database and auth helpers
require_once(__DIR__ . '/../src/Database/Database.php');
require_once(__DIR__ . '/includes/db.php'); // Initializes $db global or returns instance
require_once(__DIR__ . '/includes/auth.php'); // Handles auth check and redirection

$dbInstance = \EDUC\Database\Database::getInstance(getenv('DB_PATH'));

// Check if logged in
if (!is_logged_in()) {
    // Display login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body>
        <div class="login-container">
            <h1>Admin Panel Login</h1>
            <?php if (isset($_GET['error'])): ?>
                <p class="error"><?php echo htmlspecialchars($_GET['error']); ?></p>
            <?php endif; ?>
            <form action="login.php" method="post">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- If logged in, display settings ---

// Fetch current settings
$settings = $dbInstance->getAllSettings(); // Use helper method

$systemPrompt = $settings['systemPrompt'] ?? '';
$model = $settings['model'] ?? '';
$botMention = $settings['botMention'] ?? '';
$debugMode = strtolower($settings['debug'] ?? 'false') === 'true';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Settings</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="admin-container">
        <h1>Admin Panel - Chatbot Settings</h1>
        <p><a href="logout.php">Logout</a></p>

        <?php if (isset($_GET['success'])): ?>
            <p class="success"><?php echo htmlspecialchars($_GET['success']); ?></p>
        <?php endif; ?>
         <?php if (isset($_GET['error'])): ?>
            <p class="error"><?php echo htmlspecialchars($_GET['error']); ?></p>
        <?php endif; ?>

        <form action="save_settings.php" method="post">
            <div class="form-group">
                <label for="systemPrompt">System Prompt:</label>
                <textarea id="systemPrompt" name="systemPrompt" rows="15" required><?php echo htmlspecialchars($systemPrompt); ?></textarea>
            </div>
            <div class="form-group">
                <label for="model">AI Model:</label>
                <input type="text" id="model" name="model" value="<?php echo htmlspecialchars($model); ?>" required>
            </div>
            <div class="form-group">
                <label for="botMention">Bot Mention Name:</label>
                <input type="text" id="botMention" name="botMention" value="<?php echo htmlspecialchars($botMention); ?>" required>
            </div>
            <div class="form-group">
                <label for="debugMode">Debug Mode:</label>
                <input type="checkbox" id="debugMode" name="debugMode" value="true" <?php echo $debugMode ? 'checked' : ''; ?>>
                <span class="hint">Displays extra info in chat responses.</span>
            </div>
            <button type="submit">Save Settings</button>
        </form>
    </div>
</body>
</html> 