<?php

declare(strict_types=1);

// The deployment system generates this file to load all environment variables.
if (file_exists(__DIR__ . '/educ-bootstrap.php')) {
    require_once __DIR__ . '/educ-bootstrap.php';
} elseif (file_exists(__DIR__ . '/auto-include.php')) {
    require_once __DIR__ . '/auto-include.php';
}

require_once __DIR__ . '/src/bootstrap.php';

$db = NextcloudBot\getDbConnection();

echo "Debugging onboarding custom questions...\n\n";

// Check what's stored in bot_settings for custom questions
$stmt = $db->query("SELECT onboarding_group_questions, onboarding_dm_questions FROM bot_settings WHERE id = 1");
$settings = $stmt->fetch();

echo "Raw database values:\n";
echo "Group questions: " . var_export($settings['onboarding_group_questions'], true) . "\n";
echo "DM questions: " . var_export($settings['onboarding_dm_questions'], true) . "\n\n";

// Simulate what happens in OnboardingManager
$isGroup = true;
$stage = 6;

$customQuestionsRaw = $isGroup 
    ? ($settings['onboarding_group_questions'] ?? '[]')
    : ($settings['onboarding_dm_questions'] ?? '[]');

echo "Selected custom questions raw: " . var_export($customQuestionsRaw, true) . "\n";
echo "Type: " . gettype($customQuestionsRaw) . "\n";

if (is_string($customQuestionsRaw)) {
    $customQuestions = json_decode($customQuestionsRaw, true) ?: [];
    echo "Decoded as JSON: " . var_export($customQuestions, true) . "\n";
} else {
    $customQuestions = $customQuestionsRaw;
    echo "Used as-is: " . var_export($customQuestions, true) . "\n";
}

$questionIndex = $stage - 2; // 6 - 2 = 4
echo "Question index: {$questionIndex}\n";
echo "Number of custom questions: " . count($customQuestions) . "\n";

if (isset($customQuestions[$questionIndex])) {
    echo "Question at index {$questionIndex}: " . var_export($customQuestions[$questionIndex], true) . "\n";
} else {
    echo "No question found at index {$questionIndex}\n";
}

// Show what happens if we treat it as a string by mistake
if (is_string($customQuestionsRaw)) {
    echo "\nIf we accidentally access the JSON string by index:\n";
    for ($i = 0; $i < min(10, strlen($customQuestionsRaw)); $i++) {
        echo "Index {$i}: '" . $customQuestionsRaw[$i] . "'\n";
    }
}

echo "\nDone!\n"; 