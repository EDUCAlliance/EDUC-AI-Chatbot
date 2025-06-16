<?php

// The deployment system generates this file to load all environment variables.
if (file_exists(__DIR__ . '/auto-include.php')) {
    require_once __DIR__ . '/auto-include.php';
}

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use NextcloudBot\Services\ApiClient;
use NextcloudBot\Services\EmbeddingService;
use NextcloudBot\Services\OnboardingManager;
use NextcloudBot\Services\VectorStore;
use NextcloudBot\Helpers\Logger;

// --- Initialization ---
$logger = new Logger();
$db = NextcloudBot\getDbConnection();
$apiClient = new ApiClient(NextcloudBot\env('AI_API_KEY'), NextcloudBot\env('AI_API_ENDPOINT'), $logger);
$vectorStore = new VectorStore($db);
$embeddingService = new EmbeddingService($apiClient, $vectorStore, $logger);
$onboardingManager = new OnboardingManager($db);

$secret = NextcloudBot\env('BOT_TOKEN');
$ncUrl = NextcloudBot\env('NC_URL');

// --- 1. Receive & Verify Webhook ---
$inputContent = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
$random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';
$generatedDigest = hash_hmac('sha256', $random . $inputContent, $secret);

if (!hash_equals($generatedDigest, strtolower($signature))) {
    $logger->warning('Invalid webhook signature received.');
    http_response_code(401);
    exit('Invalid signature.');
}

$data = json_decode($inputContent, true);
$logger->info('Webhook received', ['data' => $data]);

// --- 2. Extract Data & Basic Validation ---
$message = $data['object']['content']['message'] ?? '';
$roomToken = $data['target']['id'] ?? null;
$userId = $data['actor']['id'] ?? null;
$messageId = (int)($data['object']['id'] ?? 0);

if (empty($message) || empty($roomToken) || empty($userId)) {
    $logger->error('Webhook payload missing required fields.', ['data' => $data]);
    http_response_code(400);
    exit('Missing required fields.');
}

// --- 3. Room Configuration & Onboarding ---
$stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
$stmt->execute([$roomToken]);
$roomConfig = $stmt->fetch();

// Function to send a reply to Nextcloud Talk
function sendReply(string $message, string $roomToken, int $replyToId, string $ncUrl, string $secret, Logger $logger) {
    $apiUrl = 'https://' . $ncUrl . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $roomToken . '/message';
    $requestBody = [
        'message' => $message,
        'referenceId' => bin2hex(random_bytes(16)),
        'replyTo' => $replyToId,
    ];
    $jsonBody = json_encode($requestBody);
    $random = bin2hex(random_bytes(32));
    $hash = hash_hmac('sha256', $random . $requestBody['message'], $secret);
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'OCS-APIRequest: true',
        'X-Nextcloud-Talk-Bot-Random: ' . $random,
        'X-Nextcloud-Talk-Bot-Signature: ' . $hash,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        $logger->error('Failed to send reply to Nextcloud', ['code' => $httpCode, 'response' => $response]);
    } else {
        $logger->info('Successfully sent reply to Nextcloud.', ['message' => $message]);
    }
}

// If no config, start onboarding
if (!$roomConfig) {
    // Create initial record
    $meta = ['stage' => 0, 'answers' => []];
    $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, is_group, mention_mode, meta) VALUES (?, ?, ?, ?)");
    $stmt->execute([$roomToken, true, 'on_mention', json_encode($meta)]);
    $roomConfig = ['room_token' => $roomToken, 'is_group' => true, 'mention_mode' => 'on_mention', 'onboarding_done' => false, 'meta' => $meta];
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
}


if ($roomConfig['onboarding_done'] == false) {
    // Process the answer
    $onboardingManager->processAnswer($roomConfig, $message);
    
    // Get next question
    $settingsStmt = $db->query("SELECT * FROM bot_settings WHERE id = 1");
    $globalSettings = $settingsStmt->fetch() ?: [];
    $nextStep = $onboardingManager->getNextQuestion($roomConfig, $globalSettings);
    
    sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
    exit;
}

// --- 4. Process Regular Message ---

// Store user message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content) VALUES (?, ?, 'user', ?)");
$stmt->execute([$roomToken, $userId, $message]);

// --- 5. RAG Context ---
$embeddingResponse = $apiClient->getEmbedding($message);
$ragContext = '';
if (!isset($embeddingResponse['error']) && !empty($embeddingResponse['data'][0]['embedding'])) {
    $similarChunks = $vectorStore->findSimilar($embeddingResponse['data'][0]['embedding'], 3);
    if (!empty($similarChunks)) {
        $ragContext = "Here is some context that might be relevant:\n\n---\n" . implode("\n\n", $similarChunks) . "\n---\n\n";
    }
}

// --- 6. LLM Call ---
// Fetch history
$historyStmt = $db->prepare("SELECT role, content FROM bot_conversations WHERE room_token = ? ORDER BY created_at DESC LIMIT 10");
$historyStmt->execute([$roomToken]);
$history = array_reverse($historyStmt->fetchAll());

// Fetch system prompt
$settingsStmt = $db->query("SELECT system_prompt, default_model FROM bot_settings WHERE id = 1");
$globalSettings = $settingsStmt->fetch() ?: ['system_prompt' => 'You are a helpful assistant.', 'default_model' => 'meta-llama-3.1-8b-instruct'];
$systemPrompt = $globalSettings['system_prompt'];
$model = $globalSettings['default_model'];

// Compose messages for API
$messages = [['role' => 'system', 'content' => $ragContext . $systemPrompt]];
foreach ($history as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}

$llmResponse = $apiClient->getChatCompletions($model, $messages);
$replyContent = $llmResponse['choices'][0]['message']['content'] ?? 'Sorry, I encountered an error and cannot reply right now.';

// --- 7. Reply ---
// Store assistant message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content) VALUES (?, ?, 'assistant', ?)");
$stmt->execute([$roomToken, 'assistant', $replyContent]);

// Send reply to Nextcloud
sendReply($replyContent, $roomToken, $messageId, $ncUrl, $secret, $logger); 