<?php

declare(strict_types=1);

// The deployment system generates this file to load all environment variables.
if (file_exists(__DIR__ . '/educ-bootstrap.php')) {
    require_once __DIR__ . '/educ-bootstrap.php';
} elseif (file_exists(__DIR__ . '/auto-include.php')) {
    require_once __DIR__ . '/auto-include.php';
}

require_once __DIR__ . '/src/bootstrap.php';

use NextcloudBot\Services\ApiClient;
use NextcloudBot\Helpers\Logger;

echo "Testing LLM API directly...\n\n";

$logger = new Logger();
$apiClient = new ApiClient(
    NextcloudBot\env('AI_API_KEY'),
    NextcloudBot\env('AI_API_ENDPOINT', 'https://chat-ai.academiccloud.de/v1'),
    $logger
);

// Test simple message
$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Hello! Please respond with a simple greeting.']
];

echo "Sending test message to LLM...\n";
echo "Model: meta-llama-3.1-8b-instruct\n";
echo "Messages: " . json_encode($messages, JSON_PRETTY_PRINT) . "\n\n";

try {
    $response = $apiClient->getChatCompletions('meta-llama-3.1-8b-instruct', $messages);
    
    echo "Full LLM Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($response['choices'][0]['message']['content'])) {
        $content = $response['choices'][0]['message']['content'];
        echo "Extracted Content:\n";
        echo "Length: " . strlen($content) . " characters\n";
        echo "Content: '" . $content . "'\n";
        echo "First 100 chars: '" . substr($content, 0, 100) . "'\n";
        echo "Last 100 chars: '" . substr($content, -100) . "'\n";
        
        // Check for common issues
        if (trim($content) === '') {
            echo "❌ WARNING: Content is empty or whitespace only!\n";
        } elseif (strlen($content) < 10) {
            echo "❌ WARNING: Content is suspiciously short!\n";
        } else {
            echo "✅ Content looks normal\n";
        }
    } else {
        echo "❌ ERROR: No content found in response\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n"; 