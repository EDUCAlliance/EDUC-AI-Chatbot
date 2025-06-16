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

// --- Check for Bot Mention (early exit if not mentioned) ---
try {
    $settingsStmt = $db->query("SELECT mention_name FROM bot_settings WHERE id = 1");
    $settings = $settingsStmt->fetch();
    $botMention = $settings['mention_name'] ?? '@educai';
} catch (\PDOException $e) {
    $logger->error('Failed to fetch bot mention setting', ['error' => $e->getMessage()]);
    $botMention = '@educai'; // Fallback to default
}

// Remove @ if present in the stored mention
$mentionName = ltrim($botMention, '@');

$logger->info('Checking for bot mention', [
    'botMention' => $botMention,
    'mentionName' => $mentionName,
    'message' => $message
]);

// Check if message contains the bot mention (only if not in onboarding)
// During onboarding, we process all messages from the user
$roomConfigStmt = $db->prepare("SELECT mention_mode, onboarding_done FROM bot_room_config WHERE room_token = ?");
$roomConfigStmt->execute([$roomToken]);
$roomConfigInfo = $roomConfigStmt->fetch();

$shouldCheckMention = true;
if ($roomConfigInfo && $roomConfigInfo['onboarding_done'] == false) {
    $shouldCheckMention = false; // Skip mention check during onboarding
    $logger->info('In onboarding mode, processing message without mention check');
} elseif ($roomConfigInfo && $roomConfigInfo['mention_mode'] === 'always') {
    $shouldCheckMention = false; // Always respond mode
    $logger->info('Always respond mode, processing message without mention check');
}

// Check for special reset command
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

if ($shouldCheckMention && stripos($message, '@' . $mentionName) === false) {
    $logger->info('Bot not mentioned, ignoring message', ['message' => $message, 'required_mention' => $botMention]);
    http_response_code(200);
    exit('Bot not mentioned.');
}

// --- 3. Room Configuration & Onboarding ---
try {
    $stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
    $stmt->execute([$roomToken]);
    $roomConfig = $stmt->fetch();
} catch (\PDOException $e) {
    $logger->error('Failed to fetch room config', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
    $roomConfig = false; // Will trigger creation of new config
}

// Function to send a reply to Nextcloud Talk
function sendReply(string $message, string $roomToken, int $replyToId, string $ncUrl, string $secret, Logger $logger) {
    $apiUrl = 'https://' . $ncUrl . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $roomToken . '/message';
    $requestBody = [
        'message' => $message,
        'referenceId' => bin2hex(random_bytes(32)), // Use longer reference ID as per docs
        'replyTo' => $replyToId,
        'silent' => false // Explicit silent parameter
    ];
    $jsonBody = json_encode($requestBody);
    $random = bin2hex(random_bytes(32));
    $hash = hash_hmac('sha256', $random . $requestBody['message'], $secret);
    
    $logger->info('Sending reply to Nextcloud', [
        'apiUrl' => $apiUrl,
        'roomToken' => $roomToken,
        'replyToId' => $replyToId,
        'messagePreview' => substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
        'fullMessage' => $message,
        'messageLength' => strlen($message),
        'requestBody' => $requestBody
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
            'requestBody' => $requestBody,
            'jsonBody' => $jsonBody
        ]);
        return false;
    } else {
        $logger->info('Successfully sent reply to Nextcloud.', [
            'httpCode' => $httpCode,
            'messageLength' => strlen($message),
            'response' => $response
        ]);
        return true;
    }
}

// If no config, start onboarding
if (!$roomConfig) {
    $logger->info('No room config found, starting onboarding', ['roomToken' => $roomToken]);
    
    // Try to determine if this is a group chat or direct message
    // This is a heuristic - in real scenarios you might need to call Nextcloud API to get room info
    $isGroup = true; // Default to group chat
    $roomName = $data['target']['name'] ?? '';
    
    // If room name looks like a direct message (e.g., contains user names), treat as DM
    // This is simplified logic - you might want to enhance this based on your needs
    if (strpos($roomName, ', ') === false && !empty($roomName)) {
        // Might be a direct message if no comma in name (single user)
        $logger->info('Detected potential direct message', ['roomName' => $roomName]);
    }
    
    // Create initial record - use the correct database schema
    $meta = ['stage' => 0, 'answers' => []];
    
    // Ensure boolean values are properly typed for PostgreSQL
    $isGroupBool = $isGroup ? true : false;
    $onboardingDoneBool = false;
    
    $logger->info('Inserting room config', [
        'roomToken' => $roomToken,
        'isGroup' => $isGroupBool,
        'onboardingDone' => $onboardingDoneBool
    ]);
    
    try {
        $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, is_group, mention_mode, onboarding_done, meta) VALUES (?, ?, ?, ?, ?)");
        
        // Bind parameters with explicit types for PostgreSQL boolean compatibility
        $stmt->bindValue(1, $roomToken, \PDO::PARAM_STR);
        $stmt->bindValue(2, $isGroupBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, 'on_mention', \PDO::PARAM_STR);
        $stmt->bindValue(4, $onboardingDoneBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(5, json_encode($meta), \PDO::PARAM_STR);
        
        $stmt->execute();
        $logger->info('Successfully created room config');
    } catch (\PDOException $e) {
        $logger->error('Failed to create room config', [
            'error' => $e->getMessage(),
            'roomToken' => $roomToken,
            'isGroup' => $isGroupBool,
            'onboardingDone' => $onboardingDoneBool
        ]);
        http_response_code(500);
        exit('Database error creating room config.');
    }
    $roomConfig = [
        'room_token' => $roomToken, 
        'is_group' => $isGroupBool, 
        'mention_mode' => 'on_mention', 
        'onboarding_done' => $onboardingDoneBool, 
        'meta' => $meta
    ];
} else {
    $roomConfig['meta'] = json_decode($roomConfig['meta'], true);
}

$logger->info('Room config loaded', [
    'roomConfig' => $roomConfig,
    'onboardingDone' => $roomConfig['onboarding_done']
]);

if ($roomConfig['onboarding_done'] == false) {
    $logger->info('Processing onboarding for room', ['roomToken' => $roomToken, 'message' => $message]);

    // -------------- REUSE FLOW HANDLING ------------------
    if (($roomConfig['is_group'] ?? true) === false) {
        // Direct Message room – check if we are in the reuse confirmation sub-flow
        $reuseState = $roomConfig['meta']['reuse_state'] ?? null;
        if ($reuseState === 'pending') {
            $answer = strtolower(trim($message));
            if (in_array($answer, ['use', 'yes', 'y'])) {
                // User chose to reuse previous settings
                $reuseData = $roomConfig['meta']['reuse_data'] ?? [];
                $roomConfig['mention_mode'] = $reuseData['mention_mode'] ?? $roomConfig['mention_mode'];
                $roomConfig['meta']['answers'] = $reuseData['answers'] ?? [];
                $roomConfig['meta']['reuse_state'] = 'accepted';
                // Mark onboarding as done
                $roomConfig['onboarding_done'] = true;
                // Persist changes
                $stmt = $db->prepare("UPDATE bot_room_config SET mention_mode = :mention_mode, meta = :meta, onboarding_done = TRUE WHERE room_token = :room_token");
                $stmt->execute([
                    ':mention_mode' => $roomConfig['mention_mode'],
                    ':meta' => json_encode($roomConfig['meta']),
                    ':room_token' => $roomConfig['room_token']
                ]);

                $replyText = "✅ Previous onboarding answers applied. I'm ready to help you now!";
                sendReply($replyText, $roomToken, $messageId, $ncUrl, $secret, $logger);
                exit;
            } elseif (in_array($answer, ['reset', 'no', 'n'])) {
                // User wants to reset – discard reuse data and continue fresh onboarding (stage 1)
                $roomConfig['meta']['reuse_state'] = 'declined';
                unset($roomConfig['meta']['reuse_data']);
                // Persist meta update only
                $stmt = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :room_token");
                $stmt->execute([
                    ':meta' => json_encode($roomConfig['meta']),
                    ':room_token' => $roomConfig['room_token']
                ]);
                // Continue with normal onboarding below
            } else {
                // Ask again if unclear
                sendReply("Please reply with 'use' to reuse your previous settings or 'reset' to start over.", $roomToken, $messageId, $ncUrl, $secret, $logger);
                exit;
            }
        }
    }
    // -------------- END REUSE FLOW HANDLING --------------

    // Determine current onboarding stage (may have been updated in a previous run)
    $currentStage = $roomConfig['meta']['stage'] ?? 0;

    // If this is the very first touch (stage 0, no answers yet), we have not asked ANY question.
    // Ask the first question *without* processing the current message as an answer.
    if ($currentStage === 0 && empty($roomConfig['meta']['answers'])) {
        $settingsStmt = $db->query("SELECT * FROM bot_settings WHERE id = 1");
        $globalSettings = $settingsStmt->fetch() ?: [];
        $nextStep = $onboardingManager->getNextQuestion($roomConfig, $globalSettings);

        $logger->info('Sending first onboarding question', ['question' => $nextStep['question']]);
        $success = sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
        if (!$success) {
            $logger->error('Failed to send onboarding reply');
        }
        exit;
    }

    // For all subsequent steps we first *process* the previous answer
    $onboardingManager->processAnswer($roomConfig, $message);

    // AFTER processing answer, check if we've just identified a DM and should search for previous onboarding data
    if (($roomConfig['is_group'] ?? true) === false && $roomConfig['meta']['stage'] === 1 && !isset($roomConfig['meta']['reuse_state'])) {
        // Search for an earlier DM room by the same user that has completed onboarding
        $sql = "SELECT brc.* FROM bot_room_config brc
                JOIN bot_conversations bc ON bc.room_token = brc.room_token AND bc.user_id = :user
                WHERE brc.is_group = FALSE AND brc.onboarding_done = TRUE
                ORDER BY brc.updated_at DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':user' => $userId]);
        $prevConfig = $stmt->fetch();
        if ($prevConfig) {
            $prevMeta = json_decode($prevConfig['meta'], true);
            $roomConfig['meta']['reuse_state'] = 'pending';
            $roomConfig['meta']['reuse_data'] = [
                'mention_mode' => $prevConfig['mention_mode'],
                'answers' => $prevMeta['answers'] ?? []
            ];
            // Save meta
            $stmt2 = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :room_token");
            $stmt2->execute([
                ':meta' => json_encode($roomConfig['meta']),
                ':room_token' => $roomConfig['room_token']
            ]);

            $reuseQuestion = "I found previous onboarding answers for you. Reply 'use' to reuse them or 'reset' to start fresh.";
            sendReply($reuseQuestion, $roomToken, $messageId, $ncUrl, $secret, $logger);
            exit;
        }
    }

    // Get the next question (or completion message)
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