<?php

declare(strict_types=1);

// Load environment and bootstrap
if (file_exists(__DIR__ . '/educ-bootstrap.php')) {
    require_once __DIR__ . '/educ-bootstrap.php';
} elseif (file_exists(__DIR__ . '/auto-include.php')) {
    require_once __DIR__ . '/auto-include.php';
}

require_once __DIR__ . '/src/bootstrap.php';

use NextcloudBot\Helpers\Logger;

echo "=== EDUC AI TalkBot Debug Script ===\n\n";

// Test environment variables
echo "1. Environment Variables:\n";
$envVars = ['BOT_TOKEN', 'NC_URL', 'AI_API_KEY', 'AI_API_ENDPOINT', 'DB_HOST', 'DB_NAME', 'DB_USER'];
foreach ($envVars as $var) {
    $value = NextcloudBot\env($var);
    $display = $value ? (strlen($value) > 20 ? substr($value, 0, 10) . '...' : $value) : 'NOT SET';
    echo "  $var: $display\n";
}

// Test database connection
echo "\n2. Database Connection:\n";
try {
    $db = NextcloudBot\getDbConnection();
    echo "  ✓ Database connection successful\n";
    
    // Check tables
    $tables = ['bot_admin', 'bot_settings', 'bot_docs', 'bot_embeddings', 'bot_conversations', 'bot_room_config'];
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
            $result = $stmt->fetch();
            echo "  ✓ Table $table: {$result['count']} rows\n";
        } catch (Exception $e) {
            echo "  ✗ Table $table: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "  ✗ Database connection failed: " . $e->getMessage() . "\n";
}

// Test bot settings
echo "\n3. Bot Settings:\n";
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM bot_settings");
    $settings = $stmt->fetchAll();
    if (empty($settings)) {
        echo "  ⚠ No bot settings found. Run admin panel setup first.\n";
    } else {
        foreach ($settings as $setting) {
            $value = strlen($setting['setting_value']) > 50 ? 
                substr($setting['setting_value'], 0, 50) . '...' : 
                $setting['setting_value'];
            echo "  {$setting['setting_key']}: $value\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ Error reading settings: " . $e->getMessage() . "\n";
}

// Test API client
echo "\n4. API Client:\n";
try {
    $apiClient = new NextcloudBot\Services\ApiClient(
        NextcloudBot\env('AI_API_KEY'),
        NextcloudBot\env('AI_API_ENDPOINT', 'https://chat-ai.academiccloud.de/v1'),
        new Logger()
    );
    
    // Test models endpoint
    $models = $apiClient->getModels();
    if (isset($models['data']) && !empty($models['data'])) {
        echo "  ✓ API connection successful\n";
        echo "  Available models: " . count($models['data']) . "\n";
        echo "  First model: " . ($models['data'][0]['id'] ?? 'Unknown') . "\n";
    } else {
        echo "  ✗ API connection failed or no models available\n";
        echo "  Response: " . json_encode($models) . "\n";
    }
} catch (Exception $e) {
    echo "  ✗ API test failed: " . $e->getMessage() . "\n";
}

// Test webhook simulation
echo "\n5. Webhook Simulation:\n";
$testPayload = [
    'type' => 'Create',
    'actor' => [
        'type' => 'Person',
        'id' => 'users/testuser',
        'name' => 'Test User'
    ],
    'object' => [
        'type' => 'Note',
        'id' => '123',
        'name' => 'message',
        'content' => json_encode(['message' => '@educai hello test']),
        'mediaType' => 'text/markdown'
    ],
    'target' => [
        'type' => 'Collection',
        'id' => 'testroom123',
        'name' => 'Test Room'
    ]
];

echo "  Test payload structure: ✓\n";
echo "  Message extraction test:\n";

$contentJson = $testPayload['object']['content'];
$contentData = json_decode($contentJson, true);
$message = $contentData['message'] ?? '';
echo "    Extracted message: '$message'\n";

// Test mention detection
$botMention = '@educai';
$mentionName = ltrim($botMention, '@');
$mentioned = stripos($message, '@' . $mentionName) !== false;
echo "    Bot mention detected: " . ($mentioned ? 'YES' : 'NO') . "\n";

echo "\n=== Debug Complete ===\n";
echo "If you see errors above, fix them before testing the webhook.\n";
echo "To test the webhook manually, send a POST request to /connect.php with proper headers.\n"; 