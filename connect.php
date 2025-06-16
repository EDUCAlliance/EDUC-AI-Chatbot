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
use NextcloudBot\Services\EmbeddingService;
use NextcloudBot\Services\OnboardingManager;
use NextcloudBot\Services\VectorStore;
use NextcloudBot\Helpers\Logger;

// --- Initialization ---
$logger = new Logger();
$db = NextcloudBot\getDbConnection();
$apiClient = new ApiClient(
    NextcloudBot\env('AI_API_KEY'),
    NextcloudBot\env('AI_API_ENDPOINT', 'https://chat-ai.academiccloud.de/v1'),
    $logger
);
$vectorStore = new VectorStore($db);
$embeddingService = new EmbeddingService($apiClient, $vectorStore, $logger, $db);
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
// The content is a JSON string that needs to be decoded
$contentJson = $data['object']['content'] ?? '';
$contentData = json_decode($contentJson, true);
$message = $contentData['message'] ?? '';
$roomToken = $data['target']['id'] ?? null;
$userId = $data['actor']['id'] ?? null;
$userName = $data['actor']['name'] ?? null;
$messageId = (int)($data['object']['id'] ?? 0);

$logger->info('Extracted webhook data', [
    'message' => $message,
    'roomToken' => $roomToken,
    'userId' => $userId,
    'userName' => $userName,
    'messageId' => $messageId,
    'rawContent' => $contentJson
]);

if (empty($message) || empty($roomToken) || empty($userId)) {
    $logger->error('Webhook payload missing required fields.', [
        'message' => $message,
        'roomToken' => $roomToken,
        'userId' => $userId,
        'rawData' => $data
    ]);
    http_response_code(400);
    exit('Missing required fields.');
}

// --- Check for Bot Mention (early exit if not mentioned) ---
$settingsStmt = $db->query("SELECT mention_name FROM bot_settings WHERE id = 1");
$settings = $settingsStmt->fetch();
$botMention = $settings['mention_name'] ?? '@educai';

// Remove @ if present in the stored mention
$mentionName = ltrim($botMention, '@');

$logger->info('Checking for bot mention', [
    'botMention' => $botMention,
    'mentionName' => $mentionName,
    'message' => $message
]);

// Check if message contains the bot mention
if (stripos($message, '@' . $mentionName) === false) {
    $logger->info('Bot not mentioned, ignoring message', ['message' => $message]);
    http_response_code(200);
    exit('Bot not mentioned.');
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
    
    $logger->info('Sending reply to Nextcloud', [
        'apiUrl' => $apiUrl,
        'roomToken' => $roomToken,
        'replyToId' => $replyToId,
        'messagePreview' => substr($message, 0, 100) . '...'
    ]);
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'OCS-APIRequest: true',
        'X-Nextcloud-Talk-Bot-Random: ' . $random,
        'X-Nextcloud-Talk-Bot-Signature: ' . $hash,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        $logger->error('cURL error when sending reply', ['error' => $curlError]);
        return false;
    }
    
    if ($httpCode >= 400) {
        $logger->error('Failed to send reply to Nextcloud', [
            'code' => $httpCode, 
            'response' => $response,
            'requestBody' => $requestBody
        ]);
        return false;
    } else {
        $logger->info('Successfully sent reply to Nextcloud.', [
            'httpCode' => $httpCode,
            'messageLength' => strlen($message)
        ]);
        return true;
    }
}

// If no config, start onboarding
if (!$roomConfig) {
    $logger->info('No room config found, starting onboarding', ['roomToken' => $roomToken]);
    // Create initial record
    $meta = ['stage' => 0, 'answers' => []];
    $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, room_type, onboarding_state, meta) VALUES (?, ?, ?, ?)");
    $stmt->execute([$roomToken, 'group', 'not_started', json_encode($meta)]);
    $roomConfig = [
        'room_token' => $roomToken, 
        'room_type' => 'group', 
        'onboarding_state' => 'not_started', 
        'onboarding_done' => false, 
        'meta' => $meta
    ];
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
    // Convert old column names to new ones for compatibility
    if (!isset($roomConfig['onboarding_done'])) {
        $roomConfig['onboarding_done'] = ($roomConfig['onboarding_state'] ?? 'not_started') === 'completed';
    }
}

$logger->info('Room config loaded', [
    'roomConfig' => $roomConfig,
    'onboardingDone' => $roomConfig['onboarding_done']
]);

if ($roomConfig['onboarding_done'] == false) {
    $logger->info('Processing onboarding for room', ['roomToken' => $roomToken, 'message' => $message]);
    
    // Process the answer
    $onboardingManager->processAnswer($roomConfig, $message);
    
    // Get next question
    $settingsStmt = $db->query("SELECT * FROM bot_settings WHERE id = 1");
    $globalSettings = $settingsStmt->fetch() ?: [];
    $nextStep = $onboardingManager->getNextQuestion($roomConfig, $globalSettings);
    
    $logger->info('Sending onboarding question', ['question' => $nextStep['question']]);
    
    $success = sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
    if (!$success) {
        $logger->error('Failed to send onboarding reply');
    }
    exit;
}

// --- 4. Process Regular Message ---
$logger->info('Processing regular message', ['roomToken' => $roomToken, 'userId' => $userId, 'message' => $message]);

// Store user message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content) VALUES (?, ?, 'user', ?)");
$stmt->execute([$roomToken, $userId, $message]);

// --- 5. RAG Context ---
$settingsStmt = $db->query("SELECT embedding_model, rag_top_k FROM bot_settings WHERE id = 1");
$ragSettings = $settingsStmt->fetch();
$embeddingModel = $ragSettings['embedding_model'] ?? 'e5-mistral-7b-instruct';
$topK = (int)($ragSettings['rag_top_k'] ?? 3);

$ragContext = '';
try {
    $embeddingResponse = $apiClient->getEmbedding($message, $embeddingModel);
    $logger->info('Embedding response received', ['hasError' => isset($embeddingResponse['error'])]);
    
    if (!isset($embeddingResponse['error']) && !empty($embeddingResponse['data'][0]['embedding'])) {
        $similarChunks = $vectorStore->findSimilar($embeddingResponse['data'][0]['embedding'], $topK);
        $logger->info('Similar chunks found', ['count' => count($similarChunks)]);
        
        if (!empty($similarChunks)) {
            $ragContext = "Here is some context that might be relevant:\n\n---\n" . implode("\n\n", $similarChunks) . "\n---\n\n";
        }
    } else {
        $logger->warning('No embedding data received', ['response' => $embeddingResponse]);
    }
} catch (Exception $e) {
    $logger->error('Error generating embeddings', ['error' => $e->getMessage()]);
}

// --- 6. LLM Call ---
// Fetch history
$historyStmt = $db->prepare("SELECT role, content FROM bot_conversations WHERE room_token = ? ORDER BY created_at DESC LIMIT 10");
$historyStmt->execute([$roomToken]);
$history = array_reverse($historyStmt->fetchAll());

// Fetch system prompt and model
$settingsStmt = $db->query("SELECT system_prompt, default_model FROM bot_settings WHERE id = 1");
$globalSettings = $settingsStmt->fetch() ?: ['system_prompt' => 'You are a helpful assistant.', 'default_model' => 'meta-llama-3.1-8b-instruct'];
$systemPrompt = $globalSettings['system_prompt'];
$model = $globalSettings['default_model'];

// Compose messages for API
$messages = [['role' => 'system', 'content' => $ragContext . $systemPrompt]];
foreach ($history as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}

$logger->info('Sending request to LLM', ['model' => $model, 'messageCount' => count($messages)]);

try {
    $llmResponse = $apiClient->getChatCompletions($model, $messages);
    $logger->info('LLM response received', ['hasChoices' => isset($llmResponse['choices'])]);
    
    $replyContent = $llmResponse['choices'][0]['message']['content'] ?? 'Sorry, I encountered an error and cannot reply right now.';
} catch (Exception $e) {
    $logger->error('Error calling LLM API', ['error' => $e->getMessage()]);
    $replyContent = 'Sorry, I encountered an error and cannot reply right now.';
}

// --- 7. Reply ---
// Store assistant message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content) VALUES (?, ?, 'assistant', ?)");
$stmt->execute([$roomToken, 'assistant', $replyContent]);

// Send reply to Nextcloud
$logger->info('Sending final reply', ['replyLength' => strlen($replyContent)]);
$success = sendReply($replyContent, $roomToken, $messageId, $ncUrl, $secret, $logger);

if (!$success) {
    $logger->error('Failed to send final reply to Nextcloud');
} else {
    $logger->info('Successfully completed message processing');
} 