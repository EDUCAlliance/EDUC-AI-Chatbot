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

$webhookUrl = 'http://localhost/apps/educ-ai-chatbot/connect.php'; // Adjust URL as needed
$secret = 'your-bot-token-here'; // Replace with your actual BOT_TOKEN

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
} elseif ($httpCode == 401) {
    echo "\n✗ Authentication failed - check your BOT_TOKEN\n";
} elseif ($httpCode == 400) {
    echo "\n✗ Bad request - check the payload format\n";
} else {
    echo "\n✗ Unexpected error - check server logs\n";
} 