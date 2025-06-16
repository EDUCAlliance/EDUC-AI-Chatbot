<?php
/**
 * Test script to simulate a Nextcloud Talk webhook call
 * This helps debug the bot without needing actual Nextcloud messages
 */

// Test payload that matches what Nextcloud sends
$testPayload = [
    'type' => 'Create',
    'actor' => [
        'type' => 'Person',
        'id' => 'users/admin',
        'name' => 'admin'
    ],
    'object' => [
        'type' => 'Note',
        'id' => '182',
        'name' => 'message',
        'content' => json_encode(['message' => 'tell me about EDUC @educai', 'parameters' => []]),
        'mediaType' => 'text/markdown'
    ],
    'target' => [
        'type' => 'Collection',
        'id' => '7fxkpsy6',
        'name' => 'Test Room'
    ]
];

// Configuration - UPDATE THESE VALUES
$webhookUrl = 'https://ai.cloudron.myownapp.net/apps/educ-ai-chatbot/connect.php'; // Adjust URL as needed
$secret = 'your-bot-token-here'; // Replace with your actual BOT_TOKEN from environment

$jsonPayload = json_encode($testPayload);
$random = bin2hex(random_bytes(32));
$signature = hash_hmac('sha256', $random . $jsonPayload, $secret);

echo "Testing webhook at: $webhookUrl\n";
echo "Payload: " . substr($jsonPayload, 0, 100) . "...\n";
echo "Random: $random\n";
echo "Signature: $signature\n\n";

// Send the webhook
$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Nextcloud-Talk-Signature: ' . $signature,
    'X-Nextcloud-Talk-Random: ' . $random,
    'User-Agent: Nextcloud Server Crawler'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "Response Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response Body: " . ($response ?: 'No response') . "\n";

if ($httpCode == 200) {
    echo "\n✓ Webhook test successful!\n";
    echo "This means the bot received and processed your message.\n";
    echo "Check your bot logs for processing details.\n";
} elseif ($httpCode == 401) {
    echo "\n✗ Authentication failed - check your BOT_TOKEN\n";
    echo "Make sure BOT_TOKEN in your environment matches the secret used here.\n";
} elseif ($httpCode == 400) {
    echo "\n✗ Bad request - check the payload format or database schema\n";
    echo "This often indicates database schema issues. Try accessing the admin panel first.\n";
} elseif ($httpCode == 500) {
    echo "\n✗ Internal server error - check PHP error logs\n";
    echo "Look at the server logs for detailed error information.\n";
} else {
    echo "\n✗ Unexpected error (HTTP $httpCode) - check server logs\n";
}

echo "\nNext steps:\n";
echo "1. Check bot logs: tail -f logs/app.log\n";
echo "2. Check PHP error logs for detailed errors\n";
echo "3. Try mentioning the bot in Nextcloud Talk: '@educai hello'\n"; 