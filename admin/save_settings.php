<?php
session_save_path('/app/code/public'); // Set session path *before* session_start()

session_start();

require_once(__DIR__ . '/includes/env_loader.php');
loadAdminEnv();

require_once(__DIR__ . '/../src/Database/Database.php');
require_once(__DIR__ . '/includes/db.php'); 
require_once(__DIR__ . '/includes/auth.php');

// Ensure user is logged in
if (!is_logged_in()) {
    header('Location: index.php?error=' . urlencode('Not logged in.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $systemPrompt = $_POST['systemPrompt'] ?? '';
    $model = $_POST['model'] ?? '';
    $botMention = $_POST['botMention'] ?? '';
    // Checkbox value is 'true' if checked, otherwise not present
    $debugMode = isset($_POST['debugMode']) && $_POST['debugMode'] === 'true' ? 'true' : 'false';

    // Basic validation (no need to validate debugMode as it defaults)
    if (empty($systemPrompt) || empty($model) || empty($botMention)) {
        header('Location: index.php?error=' . urlencode('All fields are required.'));
        exit;
    }

    try {
        $dbInstance = \EDUC\Database\Database::getInstance(getenv('DB_PATH'));
        $db = $dbInstance->getConnection();
        $db->beginTransaction();

        // Use the saveSetting helper method
        $dbInstance->saveSetting('systemPrompt', $systemPrompt);
        $dbInstance->saveSetting('model', $model);
        $dbInstance->saveSetting('botMention', $botMention);
        $dbInstance->saveSetting('debug', $debugMode);

        $db->commit();
        header('Location: index.php?success=' . urlencode('Settings saved successfully.'));
    } catch (\Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error saving settings: " . $e->getMessage());
        header('Location: index.php?error=' . urlencode('Error saving settings. Check server logs.'));
    }
    exit;
} else {
    // Redirect if not POST
    header('Location: index.php');
    exit;
} 