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

// --- Add CORS headers for cross-origin requests ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

    if ($detectedBot) {
        $logger->info('Room has assigned bot', ['room_token' => $roomToken, 'bot_id' => $currentBotId]);
        $allBotsStmt = $db->query("SELECT mention_name FROM bots WHERE id != {$currentBotId}");
        $otherBots = $allBotsStmt->fetchAll();

        foreach ($otherBots as $otherBot) {
            $otherMentionName = ltrim($otherBot['mention_name'], '@');
            if (stripos($message, '@' . $otherMentionName) !== false) {
                $logger->info('User trying to mention different bot', ['assigned_bot' => $detectedBot['mention_name'], 'mentioned_bot' => $otherBot['mention_name']]);
                $switchBotMessage = "In this chat-room the EDUC AI is assigned to the {$detectedBot['mention_name']} bot. To use a different bot, please restart this chat room with the reset command: ((RESET))";
                sendApiReply($switchBotMessage, $callbackUrl, $messageId, $logger);
                exit;
            }
        }

        $mentionName = ltrim($detectedBot['mention_name'], '@');
        $shouldCheckMention = $roomConfigInfo['onboarding_done'] && ($roomConfigInfo['mention_mode'] ?? 'on_mention') === 'on_mention';

        if ($shouldCheckMention && stripos($message, '@' . $mentionName) === false) {
            $logger->info('Assigned bot not mentioned, ignoring message', ['message' => $message, 'required_mention' => $detectedBot['mention_name']]);
            http_response_code(200);
            exit('Bot not mentioned.');
        }
    }
} else {
    $botsStmt = $db->query("SELECT * FROM bots ORDER BY created_at ASC");
    $allBots = $botsStmt->fetchAll();

    foreach ($allBots as $bot) {
        $mentionName = ltrim($bot['mention_name'], '@');
        if (stripos($message, '@' . $mentionName) !== false) {
            $detectedBot = $bot;
            $currentBotId = $bot['id'];
            $logger->info('New room - bot detected by mention', ['bot_id' => $currentBotId, 'bot_name' => $bot['bot_name']]);
            break;
        }
    }
    
    if (!$detectedBot && !empty($allBots)) {
        $detectedBot = $allBots[0];
        $currentBotId = $detectedBot['id'];
        $logger->info('New room - no mention detected, assigning oldest bot', ['bot_id' => $currentBotId, 'bot_name' => $detectedBot['bot_name']]);
    }
}

if (!$detectedBot) {
    $logger->error('No bot found for processing.');
    http_response_code(500);
    exit('No bot found.');
}

// --- Special RESET command ---
if (stripos($message, '((RESET))') !== false) {
    $logger->info('Reset command detected', ['roomToken' => $roomToken, 'userId' => $userId]);
    try {
        $stmtDelConfig = $db->prepare("DELETE FROM bot_room_config WHERE room_token = ?");
        $stmtDelConfig->execute([$roomToken]);
        $stmtDelConvo = $db->prepare("DELETE FROM bot_conversations WHERE room_token = ?");
        $stmtDelConvo->execute([$roomToken]);
        $logger->info('Room reset completed', ['roomToken' => $roomToken]);
        $resetMessage = "🔄 Bot has been reset for this room! I've cleared my memory and configuration. Send me a message to start fresh onboarding.";
        sendApiReply($resetMessage, $callbackUrl, $messageId, $logger);
    } catch (\PDOException $e) {
        $logger->error('Failed to reset room', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
        $errorMessage = "❌ Sorry, I couldn't reset the room due to a database error.";
        sendApiReply($errorMessage, $callbackUrl, $messageId, $logger);
    }
    exit;
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
        $logger->error('Failed to create room config', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
        http_response_code(500);
        exit('Database error creating room config.');
    }
    $roomConfig = [
        'room_token' => $roomToken, 'is_group' => $isGroupBool, 'mention_mode' => 'on_mention',
        'onboarding_done' => $onboardingDoneBool, 'meta' => $meta, 'bot_id' => $currentBotId
    ];
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
}

if ($roomConfig['onboarding_done'] == false) {
    $logger->info('Processing onboarding for room', ['roomToken' => $roomToken, 'message' => $message]);

    if (($roomConfig['is_group'] ?? true) === false) {
        $reuseState = $roomConfig['meta']['reuse_state'] ?? null;
        if ($reuseState === 'pending') {
            $answer = strtolower(trim($message));
            if (in_array($answer, ['use', 'yes', 'y'])) {
                $reuseData = $roomConfig['meta']['reuse_data'] ?? [];
                $roomConfig['mention_mode'] = $reuseData['mention_mode'] ?? $roomConfig['mention_mode'];
                $roomConfig['meta']['answers'] = $reuseData['answers'] ?? [];
                $roomConfig['meta']['reuse_state'] = 'accepted';
                $roomConfig['onboarding_done'] = true;
                $stmt = $db->prepare("UPDATE bot_room_config SET mention_mode = :mode, meta = :meta, onboarding_done = TRUE WHERE room_token = :token");
                $stmt->execute([':mode' => $roomConfig['mention_mode'], ':meta' => json_encode($roomConfig['meta']), ':token' => $roomConfig['room_token']]);
                $replyText = "✅ Previous onboarding answers applied. I'm ready to help you now!";
                sendApiReply($replyText, $callbackUrl, $messageId, $logger);
                exit;
            } elseif (in_array($answer, ['reset', 'no', 'n'])) {
                $roomConfig['meta']['reuse_state'] = 'declined';
                unset($roomConfig['meta']['reuse_data']);
                $stmt = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :token");
                $stmt->execute([':meta' => json_encode($roomConfig['meta']), ':token' => $roomConfig['room_token']]);
            } else {
                sendApiReply("Please reply with 'use' to reuse your previous settings or 'reset' to start over.", $callbackUrl, $messageId, $logger);
                exit;
            }
        }
    }

    $stageBeforeProcessing = $roomConfig['meta']['stage'] ?? 0;
    if ($stageBeforeProcessing === 0 && ($roomConfig['meta']['first_question_asked'] ?? false) === false) {
        $nextStep = $onboardingManager->getNextQuestion($roomConfig, $detectedBot);
        $roomConfig['meta']['first_question_asked'] = true;
        $stmtUpdateMeta = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :token");
        $stmtUpdateMeta->execute([':meta' => json_encode($roomConfig['meta']), ':token' => $roomConfig['room_token']]);
        sendApiReply($nextStep['question'], $callbackUrl, $messageId, $logger);
        exit;
    }

    $answerValid = $onboardingManager->processAnswer($roomConfig, $message);

    if (!$answerValid) {
        $currentStage = $roomConfig['meta']['stage'] ?? 0;
        $helpMessage = '';
        switch ($currentStage) {
            case 0: $helpMessage = "I didn't understand. Please reply with 'group' or 'dm'."; break;
            case 1: $helpMessage = "I didn't understand. Please reply with 'always' or 'on_mention'."; break;
            default: $helpMessage = "Please provide an answer to continue."; break;
        }
        sendApiReply($helpMessage, $callbackUrl, $messageId, $logger);
        exit;
    }

    if (($roomConfig['is_group'] ?? true) === false && $roomConfig['meta']['stage'] === 1 && !isset($roomConfig['meta']['reuse_state'])) {
        try {
            $sql = "SELECT brc.* FROM bot_room_config brc JOIN bot_conversations bc ON bc.room_token = brc.room_token AND bc.user_id = ? WHERE brc.is_group = FALSE AND brc.onboarding_done = TRUE ORDER BY brc.updated_at DESC LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            if ($prevConfig = $stmt->fetch()) {
                $prevMeta = json_decode($prevConfig['meta'], true);
                $roomConfig['meta']['reuse_state'] = 'pending';
                $roomConfig['meta']['reuse_data'] = ['mention_mode' => $prevConfig['mention_mode'], 'answers' => $prevMeta['answers'] ?? []];
                $stmt2 = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :token");
                $stmt2->execute([':meta' => json_encode($roomConfig['meta']), ':token' => $roomConfig['room_token']]);
                $reuseQuestion = "I found previous onboarding answers for you. Reply 'use' to reuse them or 'reset' to start fresh.";
                sendApiReply($reuseQuestion, $callbackUrl, $messageId, $logger);
                exit;
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to search for previous user config', ['error' => $e->getMessage(), 'user' => $userId]);
        }
    }

    $nextStep = $onboardingManager->getNextQuestion($roomConfig, $detectedBot);
    sendApiReply($nextStep['question'], $callbackUrl, $messageId, $logger);
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