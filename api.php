<?php

declare(strict_types=1);

// Load environment and bootstrap
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
$onboardingManager = new OnboardingManager($db, $logger);

$apiKey = NextcloudBot\env('BOT_TOKEN');

// --- 1. Receive & Verify Webhook ---
$inputContent = file_get_contents('php://input');
$headers = getallheaders();
$providedApiKey = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? '';

$logger->info('API webhook received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'hasApiKey' => !empty($providedApiKey),
    'contentLength' => strlen($inputContent)
]);

if (empty($providedApiKey) || $providedApiKey !== $apiKey) {
    $logger->warning('Invalid or missing API key', [
        'providedApiKey' => $providedApiKey
    ]);
    http_response_code(401);
    exit('Invalid API key.');
}

$data = json_decode($inputContent, true);
$logger->info('API webhook payload', ['data' => $data]);

// --- 2. Extract Data & Basic Validation ---
$message = $data['message'] ?? '';
$userId = $data['user_id'] ?? null;
$userName = $data['user_name'] ?? null;
$roomToken = $data['room_token'] ?? null;
$callbackUrl = $data['callback_url'] ?? null;
$messageId = (int)($data['message_id'] ?? 0);

$logger->info('Extracted API webhook data', [
    'message' => $message,
    'roomToken' => $roomToken,
    'userId' => $userId,
    'userName' => $userName,
    'messageId' => $messageId,
    'callbackUrl' => $callbackUrl
]);

if (empty($message) || empty($roomToken) || empty($userId) || empty($callbackUrl)) {
    $logger->error('API webhook payload missing required fields.', [
        'message' => $message,
        'roomToken' => $roomToken,
        'userId' => $userId,
        'callbackUrl' => $callbackUrl,
        'rawData' => $data
    ]);
    http_response_code(400);
    exit('Missing required fields.');
}

// --- Multi-Bot Detection & Room Association ---
$detectedBot = null;
$currentBotId = null;
$roomConfigStmt = $db->prepare("SELECT bot_id, mention_mode, onboarding_done FROM bot_room_config WHERE room_token = ?");
$roomConfigStmt->execute([$roomToken]);
$roomConfigInfo = $roomConfigStmt->fetch();
if ($roomConfigInfo && $roomConfigInfo['bot_id']) {
    $currentBotId = $roomConfigInfo['bot_id'];
    $botStmt = $db->prepare("SELECT * FROM bots WHERE id = ?");
    $botStmt->execute([$currentBotId]);
    $detectedBot = $botStmt->fetch();
} else {
    $botsStmt = $db->query("SELECT * FROM bots ORDER BY created_at ASC");
    $allBots = $botsStmt->fetchAll();
    if (!empty($allBots)) {
        $detectedBot = $allBots[0];
        $currentBotId = $detectedBot['id'];
    }
}
if (!$detectedBot) {
    $logger->error('No bot found for processing.');
    http_response_code(500);
    exit('No bot found.');
}

// --- 3. Room Configuration & Onboarding ---
try {
    $stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
    $stmt->execute([$roomToken]);
    $roomConfig = $stmt->fetch();
} catch (\PDOException $e) {
    $logger->error('Failed to fetch room config', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
    $roomConfig = false;
}
if (!$roomConfig) {
    $meta = ['stage' => 0, 'answers' => []];
    $isGroupBool = true;
    $onboardingDoneBool = false;
    try {
        $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, is_group, mention_mode, onboarding_done, meta, bot_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $roomToken, \PDO::PARAM_STR);
        $stmt->bindValue(2, $isGroupBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, 'on_mention', \PDO::PARAM_STR);
        $stmt->bindValue(4, $onboardingDoneBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(5, json_encode($meta), \PDO::PARAM_STR);
        $stmt->bindValue(6, $currentBotId, \PDO::PARAM_INT);
        $stmt->execute();
    } catch (\PDOException $e) {
        $logger->error('Failed to create room config', [
            'error' => $e->getMessage(),
            'roomToken' => $roomToken,
            'isGroup' => $isGroupBool,
            'onboardingDone' => $onboardingDoneBool,
            'botId' => $currentBotId
        ]);
        http_response_code(500);
        exit('Database error creating room config.');
    }
    $roomConfig = [
        'room_token' => $roomToken,
        'is_group' => $isGroupBool,
        'mention_mode' => 'on_mention',
        'onboarding_done' => $onboardingDoneBool,
        'meta' => $meta,
        'bot_id' => $currentBotId
    ];
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
}

if ($roomConfig['onboarding_done'] == false) {
    $logger->info('Onboarding not completed for room', ['roomToken' => $roomToken]);
    $replyText = "Onboarding is not completed for this room. Please complete onboarding in the Nextcloud client.";
    sendApiReply($replyText, $callbackUrl, $messageId, $logger);
    exit;
}

// --- 4. Process Regular Message ---
$logger->info('Processing regular message', ['roomToken' => $roomToken, 'userId' => $userId, 'message' => $message]);
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content, bot_id) VALUES (?, ?, 'user', ?, ?)");
$stmt->execute([$roomToken, $userId, $message, $currentBotId]);

// --- 4.5. Prepare Context for RAG and LLM ---
$logger->info('Preparing context for RAG and LLM');
$historyStmt = $db->prepare("SELECT role, content FROM bot_conversations WHERE room_token = ? ORDER BY created_at DESC LIMIT 10");
$historyStmt->execute([$roomToken]);
$history = array_reverse($historyStmt->fetchAll());
$onboardingStmt = $db->prepare("SELECT is_group, mention_mode, meta FROM bot_room_config WHERE room_token = ? AND onboarding_done = TRUE");
$onboardingStmt->execute([$roomToken]);
$roomConfigForPrompt = $onboardingStmt->fetch();
$onboardingQnAForEmbedding = '';
if ($roomConfigForPrompt) {
    $isGroup = (bool) $roomConfigForPrompt['is_group'];
    $meta = json_decode($roomConfigForPrompt['meta'], true);
    $answers = $meta['answers'] ?? [];
    $questionsRaw = $isGroup
        ? ($detectedBot['onboarding_group_questions'] ?? '[]')
        : ($detectedBot['onboarding_dm_questions'] ?? '[]');
    $questions = json_decode($questionsRaw, true) ?: [];
    if (!empty($answers) && !empty($questions)) {
        $qnaParts = [];
        foreach ($answers as $index => $answer) {
            if (isset($questions[$index])) {
                $qnaParts[] = "Q: " . $questions[$index] . "\nA: " . $answer;
            }
        }
        $onboardingQnAForEmbedding = implode("\n\n", $qnaParts);
    }
}
$historyForEmbedding = array_slice($history, -2);
$historyTextForEmbedding = '';
if (!empty($historyForEmbedding)) {
    $historyLines = [];
    foreach ($historyForEmbedding as $msg) {
        if ($msg['role'] === 'user' && $msg['content'] === $message) {
            continue;
        }
        $historyLines[] = $msg['role'] . ': ' . $msg['content'];
    }
    $historyTextForEmbedding = implode("\n", $historyLines);
}
$embeddingTextParts = [];
if (!empty($onboardingQnAForEmbedding)) {
    $embeddingTextParts[] = "Onboarding context:\n" . $onboardingQnAForEmbedding;
}
if (!empty($historyTextForEmbedding)) {
    $embeddingTextParts[] = "Recent conversation history:\n" . $historyTextForEmbedding;
}
$embeddingTextParts[] = "Current user message:\nuser: " . $message;
$textForEmbedding = implode("\n\n---\n\n", $embeddingTextParts);
$logger->info('Constructed text for RAG embedding', ['text' => $textForEmbedding]);

// --- 5. RAG Context ---
$embeddingModel = $detectedBot['embedding_model'] ?? 'e5-mistral-7b-instruct';
$topK = (int)($detectedBot['rag_top_k'] ?? 3);
$ragContext = '';
try {
    $embeddingResponse = $apiClient->getEmbedding($textForEmbedding, $embeddingModel);
    $logger->info('Embedding response received', ['hasError' => isset($embeddingResponse['error'])]);
    if (!isset($embeddingResponse['error']) && !empty($embeddingResponse['data'][0]['embedding'])) {
        $similarChunks = $vectorStore->findSimilar($embeddingResponse['data'][0]['embedding'], $topK, $currentBotId);
        $logger->info('Similar chunks found', ['count' => count($similarChunks), 'bot_id' => $currentBotId]);
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
$systemPrompt = $detectedBot['system_prompt'] ?? 'You are a helpful assistant.';
$model = $detectedBot['default_model'] ?? 'meta-llama-3.1-8b-instruct';
$onboardingContext = '';
if ($roomConfigForPrompt) {
    $isGroup = (bool) $roomConfigForPrompt['is_group'];
    $mentionMode = $roomConfigForPrompt['mention_mode'];
    $onboardingContext .= "\n\n--- User Onboarding Context ---\n";
    $onboardingContext .= "Chat Type: " . ($isGroup ? "Group Chat" : "Direct Message") . "\n";
    $onboardingContext .= "Bot Interaction Style: " . ($mentionMode === 'always' ? "Respond to every message" : "Respond only when mentioned (" . $detectedBot['mention_name'] . ")") . "\n";
    $questionsRaw = $isGroup
        ? ($detectedBot['onboarding_group_questions'] ?? '[]')
        : ($detectedBot['onboarding_dm_questions'] ?? '[]');
    $questions = json_decode($questionsRaw, true) ?: [];
    if (!empty($answers) && !empty($questions)) {
        $onboardingContext .= "Additional User Answers:\n";
        foreach ($answers as $index => $answer) {
            if (isset($questions[$index])) {
                $onboardingContext .= "- Q: " . $questions[$index] . "\n";
                $onboardingContext .= "  A: " . $answer . "\n";
            }
        }
    }
    $onboardingContext .= "---------------------------\n";
}
$messages = [['role' => 'system', 'content' => $onboardingContext . $ragContext . $systemPrompt]];
foreach ($history as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}
$logger->info('Sending request to LLM', ['model' => $model, 'messageCount' => count($messages)]);
try {
    $llmResponse = $apiClient->getChatCompletions($model, $messages);
    $logger->info('LLM response received', [
        'hasChoices' => isset($llmResponse['choices']),
        'fullResponse' => $llmResponse
    ]);
    $replyContent = $llmResponse['choices'][0]['message']['content'] ?? 'Sorry, I encountered an error and cannot reply right now.';
    $logger->info('Reply content extracted', [
        'replyContent' => $replyContent,
        'replyLength' => strlen($replyContent),
        'firstChars' => substr($replyContent, 0, 50)
    ]);
} catch (Exception $e) {
    $logger->error('Error calling LLM API', ['error' => $e->getMessage()]);
    $replyContent = 'Sorry, I encountered an error and cannot reply right now.';
}
// --- 7. Reply ---
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content, bot_id) VALUES (?, ?, 'assistant', ?, ?)");
$stmt->execute([$roomToken, 'assistant', $replyContent, $currentBotId]);
$logger->info('Sending API reply', ['replyLength' => strlen($replyContent)]);
sendApiReply($replyContent, $callbackUrl, $messageId, $logger);

function sendApiReply(string $message, string $callbackUrl, int $replyToId, Logger $logger) {
    $payload = [
        'message' => $message,
        'replyTo' => $replyToId,
        'success' => true
    ];
    $jsonBody = json_encode($payload);
    $ch = curl_init($callbackUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        $logger->error('cURL error when sending API reply', ['error' => $curlError]);
        return false;
    }
    if ($httpCode >= 400) {
        $logger->error('Failed to send API reply', [
            'code' => $httpCode,
            'response' => $response,
            'jsonBody' => $jsonBody
        ]);
        return false;
    } else {
        $logger->info('Successfully sent API reply.', [
            'httpCode' => $httpCode,
            'messageLength' => strlen($message),
            'response' => $response
        ]);
        return true;
    }
} 