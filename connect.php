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

$logger->info('Webhook received - basic validation', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'hasSignature' => !empty($signature),
    'hasRandom' => !empty($random),
    'contentLength' => strlen($inputContent),
    'hasSecret' => !empty($secret)
]);

$generatedDigest = hash_hmac('sha256', $random . $inputContent, $secret);

if (!hash_equals($generatedDigest, strtolower($signature))) {
    $logger->warning('Invalid webhook signature received.', [
        'expectedSignature' => $generatedDigest,
        'receivedSignature' => strtolower($signature),
        'random' => $random,
        'contentLength' => strlen($inputContent)
    ]);
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
        'messageEmpty' => empty($message),
        'roomToken' => $roomToken,
        'roomTokenEmpty' => empty($roomToken),
        'userId' => $userId,
        'userIdEmpty' => empty($userId),
        'contentJson' => $contentJson,
        'contentData' => $contentData,
        'rawData' => $data
    ]);
    http_response_code(400);
    exit('Missing required fields.');
}

// --- Bot Detection and Room Configuration ---
$activeBot = null;
$roomConfig = null;

try {
    // Check if the room is already configured
    $stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
    $stmt->execute([$roomToken]);
    $roomConfig = $stmt->fetch();

    if ($roomConfig && $roomConfig['bot_id']) {
        // Room is already configured for a specific bot
        $botStmt = $db->prepare("SELECT * FROM bots WHERE id = ?");
        $botStmt->execute([$roomConfig['bot_id']]);
        $activeBot = $botStmt->fetch();
        $logger->info('Room already configured for bot.', ['bot_id' => $activeBot['id'], 'mention_name' => $activeBot['mention_name']]);
    } else {
        // New room or re-onboarding: Find which bot is being mentioned
        $allBots = $db->query("SELECT * FROM bots ORDER BY LENGTH(mention_name) DESC")->fetchAll();
        foreach ($allBots as $bot) {
            if (stripos($message, $bot['mention_name']) !== false) {
                $activeBot = $bot;
                $logger->info('Detected mention for a bot.', ['bot_id' => $activeBot['id'], 'mention_name' => $activeBot['mention_name']]);
                break;
            }
        }
    }
} catch (\PDOException $e) {
    $logger->error('Failed during bot detection or room config fetching', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Database error.');
}

// If no bot is mentioned in a new chat, or the configured bot isn't mentioned when required, exit.
if (!$activeBot) {
    $logger->info('No active bot could be determined for this message. Exiting.');
    http_response_code(200);
    exit('No relevant bot found or mentioned.');
}

// Re-check mention requirement
$shouldCheckMention = false;
if ($roomConfig && $roomConfig['onboarding_done']) {
    $shouldCheckMention = ($roomConfig['mention_mode'] ?? 'on_mention') === 'on_mention';
} elseif (!$roomConfig) {
    // First contact always requires a mention
    $shouldCheckMention = true;
}

if ($shouldCheckMention && stripos($message, $activeBot['mention_name']) === false) {
    $logger->info('Bot not mentioned, ignoring message', ['message' => $message, 'required_mention' => $activeBot['mention_name']]);
    http_response_code(200);
    exit('Bot not mentioned.');
}

// --- Special RESET command -------------------------------------------------
if (stripos($message, '((RESET))') !== false) {
    $logger->info('Reset command detected', ['roomToken' => $roomToken, 'userId' => $userId]);

    try {
        // Delete room configuration to trigger fresh onboarding
        $stmt = $db->prepare("DELETE FROM bot_room_config WHERE room_token = ?");
        $stmt->execute([$roomToken]);

        // Clear conversation history
        $stmt = $db->prepare("DELETE FROM bot_conversations WHERE room_token = ?");
        $stmt->execute([$roomToken]);

        $logger->info('Room reset completed', ['roomToken' => $roomToken]);

        $resetMessage = "🔄 Bot has been reset for this room! I've cleared my memory and configuration. Send me a message to start fresh onboarding.";
        $success = sendReply($resetMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);

        if (!$success) {
            $logger->error('Failed to send reset confirmation');
        }

    } catch (\PDOException $e) {
        $logger->error('Failed to reset room', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
        $errorMessage = "❌ Sorry, I couldn't reset the room due to a database error. Please try again or contact an administrator.";
        sendReply($errorMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);
    }

    exit;
}
// ---------------------------------------------------------------------------

// --- 3. Room Configuration & Onboarding ---
if (!$roomConfig) {
    // Create new room config for the detected bot
    $logger->info('No room config found, starting onboarding for bot.', ['roomToken' => $roomToken, 'bot_id' => $activeBot['id']]);
    
    $isGroup = true; // Default, will be asked in onboarding
    $meta = ['stage' => 0, 'answers' => []];
    
    try {
        $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, bot_id, is_group, mention_mode, onboarding_done, meta) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $roomToken);
        $stmt->bindValue(2, $activeBot['id'], \PDO::PARAM_INT);
        $stmt->bindValue(3, $isGroup, \PDO::PARAM_BOOL);
        $stmt->bindValue(4, 'on_mention');
        $stmt->bindValue(5, false, \PDO::PARAM_BOOL);
        $stmt->bindValue(6, json_encode($meta));
        $stmt->execute();
        
        $roomConfig = [
            'room_token' => $roomToken,
            'bot_id' => $activeBot['id'],
            'is_group' => $isGroup,
            'mention_mode' => 'on_mention',
            'onboarding_done' => false,
            'meta' => $meta
        ];
        $logger->info('Successfully created new room config.');
    } catch (\PDOException $e) {
        $logger->error('Failed to create room config', ['error' => $e->getMessage(), 'bot_id' => $activeBot['id']]);
        http_response_code(500);
        exit('Database error creating room config.');
    }
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
}

$logger->info('Room config loaded', [
    'roomConfig' => $roomConfig,
    'onboardingDone' => $roomConfig['onboarding_done']
]);

// --- Onboarding Logic ---
if ($roomConfig['onboarding_done'] == false) {
    $logger->info('Processing onboarding for room', ['roomToken' => $roomToken, 'bot_id' => $activeBot['id'], 'message' => $message]);

    $currentStage = $roomConfig['meta']['stage'] ?? 0;
    $firstAsked = $roomConfig['meta']['first_question_asked'] ?? false;

    // If this is the very first interaction (stage 0 and we haven't asked anything yet),
    // we should just ask the first question and exit. We don't process an answer yet.
    if ($currentStage === 0 && !$firstAsked) {
        $nextStep = $onboardingManager->getNextQuestion($roomConfig, $activeBot);

        // Mark that we are asking the first question
        $roomConfig['meta']['first_question_asked'] = true;
        $stmtUpdateMeta = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :room_token");
        $stmtUpdateMeta->execute([':meta' => json_encode($roomConfig['meta']), ':room_token' => $roomToken]);

        $logger->info('Sending first onboarding question', ['question' => $nextStep['question']]);
        sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
        exit;
    }

    // For all subsequent onboarding messages, we first process the user's answer
    $onboardingManager->processAnswer($roomConfig, $message); // This function advances the stage internally

    // Then, we get the next question (or completion message)
    $nextStep = $onboardingManager->getNextQuestion($roomConfig, $activeBot);

    $logger->info('Sending next onboarding step', ['question' => $nextStep['question'], 'is_done' => $nextStep['is_done']]);
    sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
    exit;
}

// --- 4. Process Regular Message ---
$logger->info('Processing regular message', ['roomToken' => $roomToken, 'userId' => $userId, 'message' => $message]);

// Store user message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content) VALUES (?, ?, 'user', ?)");
$stmt->execute([$roomToken, $userId, $message]);

// --- 5. RAG Context ---
$embeddingModel = $activeBot['embedding_model'] ?? 'e5-mistral-7b-instruct';
$topK = (int)($activeBot['rag_top_k'] ?? 3);

$ragContext = '';
try {
    $embeddingResponse = $apiClient->getEmbedding($message, $embeddingModel);
    $logger->info('Embedding response received', ['hasError' => isset($embeddingResponse['error'])]);
    
    if (!isset($embeddingResponse['error']) && !empty($embeddingResponse['data'][0]['embedding'])) {
        $similarChunks = $vectorStore->findSimilar($embeddingResponse['data'][0]['embedding'], $topK, $activeBot['id']);
        $logger->info('Similar chunks found', ['count' => count($similarChunks)]);
        
        if (!empty($similarChunks)) {
            $ragContext = "Here is some context that might be relevant:\n\n---\n" . implode("\n\n", $similarChunks) . "\n---\n\n";
        }
    }
} catch (Exception $e) {
    $logger->error('Error during RAG context retrieval', ['error' => $e->getMessage()]);
}

// --- 6. LLM Call ---
// Fetch history
$historyStmt = $db->prepare("SELECT role, content FROM bot_conversations WHERE room_token = ? ORDER BY created_at DESC LIMIT 10");
$historyStmt->execute([$roomToken]);
$history = array_reverse($historyStmt->fetchAll());

// Get bot-specific settings
$systemPrompt = $activeBot['system_prompt'];
$model = $activeBot['default_model'];

// --- Inject Onboarding Context into System Prompt ---
$onboardingContext = '';
if ($roomConfig && $roomConfig['onboarding_done']) {
    $isGroup = (bool) $roomConfig['is_group'];
    $mentionMode = $roomConfig['mention_mode'];
    $meta = $roomConfig['meta'] ?? []; // Already decoded
    $answers = $meta['answers'] ?? [];

    $onboardingContext .= "\n\n--- User Onboarding Context ---\n";
    $onboardingContext .= "Chat Type: " . ($isGroup ? "Group Chat" : "Direct Message") . "\n";
    $onboardingContext .= "Bot Interaction Style: " . ($mentionMode === 'always' ? "Respond to every message" : "Respond only when mentioned (" . $activeBot['mention_name'] . ")") . "\n";

    $questionsRaw = $isGroup
        ? ($activeBot['onboarding_group_questions'] ?? '[]')
        : ($activeBot['onboarding_dm_questions'] ?? '[]');
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
// --- End Onboarding Context Injection ---

// Compose messages for API
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