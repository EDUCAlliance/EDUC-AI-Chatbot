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
use NextcloudBot\Helpers\TalkHelper;

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

// --- Multi-Bot Detection & Room Association ---
$detectedBot = null;
$currentBotId = null;

// First, check if this room already has a bot assigned
$roomConfigStmt = $db->prepare("SELECT bot_id, mention_mode, onboarding_done FROM bot_room_config WHERE room_token = ?");
$roomConfigStmt->execute([$roomToken]);
$roomConfigInfo = $roomConfigStmt->fetch();

if ($roomConfigInfo && $roomConfigInfo['bot_id']) {
    // Room already has a bot assigned - only listen to that bot
    $currentBotId = $roomConfigInfo['bot_id'];
    $botStmt = $db->prepare("SELECT * FROM bots WHERE id = ?");
    $botStmt->execute([$currentBotId]);
    $detectedBot = $botStmt->fetch();
    
    if ($detectedBot) {
        $logger->info('Room has assigned bot', [
            'room_token' => $roomToken,
            'bot_id' => $currentBotId,
            'bot_name' => $detectedBot['bot_name'],
            'mention_name' => $detectedBot['mention_name']
        ]);
        
        // Check if user is trying to mention a different bot
        $allBotsStmt = $db->query("SELECT mention_name FROM bots WHERE id != {$currentBotId}");
        $otherBots = $allBotsStmt->fetchAll();
        
        foreach ($otherBots as $otherBot) {
            $otherMentionName = ltrim($otherBot['mention_name'], '@');
            if (stripos($message, '@' . $otherMentionName) !== false) {
                $logger->info('User trying to mention different bot', [
                    'room_token' => $roomToken,
                    'assigned_bot' => $detectedBot['mention_name'],
                    'mentioned_bot' => $otherBot['mention_name']
                ]);
                
                $switchBotMessage = "In this chat-room the EDUC AI is assigned to the {$detectedBot['mention_name']} bot. To use a different bot, please restart this chat room with the reset command: ((RESET))";
                TalkHelper::sendReply($switchBotMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);
                exit;
            }
        }
        
        // Check if the assigned bot is mentioned
        $mentionName = ltrim($detectedBot['mention_name'], '@');
        $shouldCheckMention = $roomConfigInfo['onboarding_done'] && 
                            ($roomConfigInfo['mention_mode'] ?? 'on_mention') === 'on_mention';
        
        if ($shouldCheckMention && stripos($message, '@' . $mentionName) === false) {
            $logger->info('Assigned bot not mentioned, ignoring message', [
                'message' => $message,
                'required_mention' => $detectedBot['mention_name']
            ]);
            http_response_code(200);
            exit('Bot not mentioned.');
        }
    }
} else {
    // New room - scan for any bot mention
    $botsStmt = $db->query("SELECT * FROM bots ORDER BY created_at ASC");
    $allBots = $botsStmt->fetchAll();
    
    foreach ($allBots as $bot) {
        $mentionName = ltrim($bot['mention_name'], '@');
        if (stripos($message, '@' . $mentionName) !== false) {
            $detectedBot = $bot;
            $currentBotId = $bot['id'];
            $logger->info('New room - bot detected', [
                'room_token' => $roomToken,
                'bot_id' => $currentBotId,
                'bot_name' => $bot['bot_name'],
                'mention_name' => $bot['mention_name']
            ]);
            break;
        }
    }
    
    if (!$detectedBot) {
        $logger->info('No bot mentioned in new room, ignoring message', ['message' => $message]);
        http_response_code(200);
        exit('No bot mentioned.');
    }
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
        $success = TalkHelper::sendReply($resetMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);

        if (!$success) {
            $logger->error('Failed to send reset confirmation');
        }

    } catch (\PDOException $e) {
        $logger->error('Failed to reset room', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
        $errorMessage = "❌ Sorry, I couldn't reset the room due to a database error. Please try again or contact an administrator.";
        TalkHelper::sendReply($errorMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);
    }

    exit;
}
// ---------------------------------------------------------------------------

// --- 3. Room Configuration & Onboarding ---
try {
    $stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
    $stmt->execute([$roomToken]);
    $roomConfig = $stmt->fetch();
} catch (\PDOException $e) {
    $logger->error('Failed to fetch room config', ['error' => $e->getMessage(), 'roomToken' => $roomToken]);
    $roomConfig = false; // Will trigger creation of new config
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
    
    // Create initial record - use the correct database schema with bot_id
    $meta = ['stage' => 0, 'answers' => []];
    
    // Ensure boolean values are properly typed for PostgreSQL
    $isGroupBool = $isGroup ? true : false;
    $onboardingDoneBool = false;
    
    $logger->info('Inserting room config', [
        'roomToken' => $roomToken,
        'isGroup' => $isGroupBool,
        'onboardingDone' => $onboardingDoneBool,
        'botId' => $currentBotId
    ]);
    
    try {
        $stmt = $db->prepare("INSERT INTO bot_room_config (room_token, is_group, mention_mode, onboarding_done, meta, bot_id) VALUES (?, ?, ?, ?, ?, ?)");
        
        // Bind parameters with explicit types for PostgreSQL boolean compatibility
        $stmt->bindValue(1, $roomToken, \PDO::PARAM_STR);
        $stmt->bindValue(2, $isGroupBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(3, 'on_mention', \PDO::PARAM_STR);
        $stmt->bindValue(4, $onboardingDoneBool, \PDO::PARAM_BOOL);
        $stmt->bindValue(5, json_encode($meta), \PDO::PARAM_STR);
        $stmt->bindValue(6, $currentBotId, \PDO::PARAM_INT);
        
        $stmt->execute();
        $logger->info('Successfully created room config with bot association');
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
                TalkHelper::sendReply($replyText, $roomToken, $messageId, $ncUrl, $secret, $logger);
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
                TalkHelper::sendReply("Please reply with 'use' to reuse your previous settings or 'reset' to start over.", $roomToken, $messageId, $ncUrl, $secret, $logger);
                exit;
            }
        }
    }
    // -------------- END REUSE FLOW HANDLING --------------

    // Determine current onboarding stage (may have been updated in a previous run)
    $currentStage = $roomConfig['meta']['stage'] ?? 0;

    // --- FIRST QUESTION HANDLING ---
    // When stage is 0 and we have not yet asked the very first question, do so now and mark it asked.
    $firstAsked = $roomConfig['meta']['first_question_asked'] ?? false;
    if ($currentStage === 0 && $firstAsked === false) {
        // Use the detected bot's settings for onboarding
        $nextStep = $onboardingManager->getNextQuestion($roomConfig, $detectedBot);

        // Mark that we already asked the first question so we don't repeat it.
        $roomConfig['meta']['first_question_asked'] = true;
        $stmtUpdateFirst = $db->prepare("UPDATE bot_room_config SET meta = :meta WHERE room_token = :room_token");
        $stmtUpdateFirst->execute([
            ':meta' => json_encode($roomConfig['meta']),
            ':room_token' => $roomConfig['room_token']
        ]);

        $logger->info('Sending first onboarding question', ['question' => $nextStep['question']]);
        $success = TalkHelper::sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
        if (!$success) {
            $logger->error('Failed to send onboarding reply');
        }
        exit;
    }
    // --- END FIRST QUESTION HANDLING ---

    // For all subsequent steps we first *process* the previous answer
    $answerValid = $onboardingManager->processAnswer($roomConfig, $message);
    
    // If the answer was invalid, ask the question again with helpful guidance
    if (!$answerValid) {
        $currentStage = $roomConfig['meta']['stage'] ?? 0;
        $helpMessage = '';
        
        switch ($currentStage) {
            case 0:
                $helpMessage = "I didn't understand your answer. Please reply with 'group' if this is a group chat, or 'dm' if this is a direct message.";
                break;
            case 1:
                $helpMessage = "I didn't understand your answer. Please reply with 'always' if I should respond to every message, or 'on_mention' if I should only respond when mentioned.";
                break;
            default:
                $helpMessage = "Please provide an answer to continue with the onboarding process.";
                break;
        }
        
        $logger->info('Invalid onboarding answer, sending help message', [
            'stage' => $currentStage,
            'userAnswer' => $message,
            'helpMessage' => $helpMessage
        ]);
        
        TalkHelper::sendReply($helpMessage, $roomToken, $messageId, $ncUrl, $secret, $logger);
        exit;
    }

    // AFTER processing answer, check if we've just identified a DM and should search for previous onboarding data
    if (($roomConfig['is_group'] ?? true) === false && $roomConfig['meta']['stage'] === 1 && !isset($roomConfig['meta']['reuse_state'])) {
        try {
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
                TalkHelper::sendReply($reuseQuestion, $roomToken, $messageId, $ncUrl, $secret, $logger);
                exit;
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to search for previous user config', ['error' => $e->getMessage(), 'user' => $userId]);
            // Do not exit. Just log the error and fall through to the next question,
            // making the experience consistent with the 'group' path.
        }
    }

    // Get the next question (or completion message) using bot-specific settings
    $nextStep = $onboardingManager->getNextQuestion($roomConfig, $detectedBot);

    $logger->info('Sending onboarding question', ['question' => $nextStep['question']]);
    $success = TalkHelper::sendReply($nextStep['question'], $roomToken, $messageId, $ncUrl, $secret, $logger);
    if (!$success) {
        $logger->error('Failed to send onboarding reply');
    }
    exit;
}

// --- 4. Process Regular Message ---
$logger->info('Processing regular message', ['roomToken' => $roomToken, 'userId' => $userId, 'message' => $message]);

// Store user message
$stmt = $db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content, bot_id) VALUES (?, ?, 'user', ?, ?)");
$stmt->execute([$roomToken, $userId, $message, $currentBotId]);

// --- 4.5. Prepare Context for RAG and LLM ---
$logger->info('Preparing context for RAG and LLM');

// Fetch conversation history (last 10 messages for LLM, 5 for RAG)
$historyStmt = $db->prepare("SELECT role, content FROM bot_conversations WHERE room_token = ? ORDER BY created_at DESC LIMIT 10");
$historyStmt->execute([$roomToken]);
$history = array_reverse($historyStmt->fetchAll());

// Fetch onboarding configuration to use for both RAG context and LLM system prompt
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

// Combine all parts for the final embedding text
$embeddingTextParts = [];
if (!empty($onboardingQnAForEmbedding)) {
    $embeddingTextParts[] = "Onboarding context:\n" . $onboardingQnAForEmbedding;
}
// Always add the current user message last, as it's the most important part
$embeddingTextParts[] = "Current user message:\nuser: " . $message;

$textForEmbedding = implode("\n\n---\n\n", $embeddingTextParts);
$logger->info('Constructed text for RAG embedding', ['text' => $textForEmbedding]);

// --- 5. RAG Context ---
// Use the detected bot's settings for RAG
$embeddingModel = $detectedBot['embedding_model'] ?? 'e5-mistral-7b-instruct';
$topK = (int)($detectedBot['rag_top_k'] ?? 3);

$ragContext = '';
try {
    // Use the combined text to generate a context-aware embedding
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
// Use the detected bot's settings for system prompt and model
$systemPrompt = $detectedBot['system_prompt'] ?? 'You are a helpful assistant.';
$model = $detectedBot['default_model'] ?? 'meta-llama-3.1-8b-instruct';

// --- Inject Onboarding Context into System Prompt ---
$onboardingContext = '';
if ($roomConfigForPrompt) {
    $isGroup = (bool) $roomConfigForPrompt['is_group'];
    $mentionMode = $roomConfigForPrompt['mention_mode'];

    $onboardingContext .= "\n\n--- User Onboarding Context ---\n";
    $onboardingContext .= "Chat Type: " . ($isGroup ? "Group Chat" : "Direct Message") . "\n";
    $onboardingContext .= "Bot Interaction Style: " . ($mentionMode === 'always' ? "Respond to every message" : "Respond only when mentioned (" . $detectedBot['mention_name'] . ")") . "\n";

    $answers = $meta['answers'] ?? []; // Ensure answers are available here as well

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
// --- End Onboarding Context Injection ---

// Compose messages for API
$messages = [['role' => 'system', 'content' => $onboardingContext . $ragContext . $systemPrompt]];
foreach ($history as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}

$logger->info('Preparing to queue LLM request', ['model' => $model, 'messageCount' => count($messages)]);

// --- 7. Queue LLM Job ---
$jobData = [
    'model' => $model,
    'messages' => $messages,
    'roomToken' => $roomToken,
    'replyToId' => $messageId,
    'ncUrl' => $ncUrl,
    'secret' => $secret,
    'botId' => $currentBotId
];

$jobId = uniqid('job_', true);
$jobFilePath = APP_ROOT . '/cache/queue/pending/' . $jobId . '.json';

if (file_put_contents($jobFilePath, json_encode($jobData, JSON_PRETTY_PRINT))) {
    $logger->info('Successfully queued LLM job', ['jobId' => $jobId, 'filePath' => $jobFilePath]);
} else {
    $logger->error('Failed to write job file to queue', ['filePath' => $jobFilePath]);
    // Optionally, send a failure message back to the user
    $failMsg = "Sorry, I couldn't process your request right now due to a temporary issue with my internal queue. Please try again in a moment.";
    TalkHelper::sendReply($failMsg, $roomToken, $messageId, $ncUrl, $secret, $logger);
}

// --- 8. Respond to Webhook ---
// Immediately respond to the webhook to prevent timeouts.
// The actual work will be done by the background worker.
http_response_code(200);
$logger->info('Webhook handler finished, job queued.');
exit('Request queued.'); 