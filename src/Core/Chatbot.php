<?php
namespace EDUC\Core;

use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\UserMessageRepository;
use EDUC\RAG\Retriever;

class Chatbot {
    private LLMClient $llmClient;
    private Database $db;
    private UserMessageRepository $messageRepository;
    private ?Retriever $retriever;
    private bool $debug;
    private const MESSAGE_EXPIRY_SECONDS = 24 * 60 * 60; // 24 hours - This is no longer used for welcome messages
    private const ONBOARDING_COMPLETED_STEP = 4;
    private const ONBOARDING_RESET_CONFIRMATION_MESSAGE = "Are you sure you want to reset your configuration and message history for this chat? Type YES to confirm or anything else to cancel.";
    
    public function __construct(
        LLMClient $llmClient,
        Database $db,
        ?Retriever $retriever = null,
        bool $debug = false
    ) {
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->messageRepository = new UserMessageRepository($db);
        $this->retriever = $retriever;
        $this->debug = $debug;
    }
    
    public function processUserMessage(string $message, string $userId, string $userName, string $targetId, string $currentTime): string {
        // Log the user message with role 'user'
        $this->messageRepository->logMessage($userId, $targetId, 'user', $message);
        
        // Fetch chat configuration for this target_id
        $chatConfig = $this->db->getChatConfig($targetId);
        $isNewChatConfig = false;
        if ($chatConfig === null) {
            $chatConfig = [
                'target_id' => $targetId,
                'is_group_chat' => false, // Default, will be asked
                'requires_mention' => true, // Default, will be asked
                'onboarding_step' => 0,
                'current_question_index' => 0,
                'onboarding_answers' => json_encode([]),
            ];
            $isNewChatConfig = true;
        }

        $onboardingAnswers = json_decode($chatConfig['onboarding_answers'], true) ?: [];

        // Handle /reset command
        if (strtolower(trim($message)) === '/reset') {
            if ($chatConfig['onboarding_step'] === -1) { // -1 indicates awaiting reset confirmation
                // This state should ideally not be hit if UI prevents sending new messages during confirmation
                // but as a fallback, we reset the confirmation state.
                $chatConfig['onboarding_step'] = $isNewChatConfig ? 0 : self::ONBOARDING_COMPLETED_STEP; // Revert to previous state or new
                $this->db->saveChatConfig($targetId, $chatConfig);
                $response = "Reset cancelled. Let's continue.";
                $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
                return $response;
            }
            $chatConfig['onboarding_step'] = -1; // Set to awaiting confirmation
            $this->db->saveChatConfig($targetId, $chatConfig);
            $response = self::ONBOARDING_RESET_CONFIRMATION_MESSAGE;
            $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
            return $response;
        }

        if ($chatConfig['onboarding_step'] === -1) { // Awaiting reset confirmation
            if (strtoupper(trim($message)) === 'YES') {
                // Perform reset
                $this->messageRepository->deleteMessagesByTarget($targetId);
                $this->db->deleteChatConfig($targetId); // Deletes the config, will be recreated on next message
                $response = "Configuration and message history for this chat have been reset. We can start fresh now!";
                // Log this reset confirmation as user and then assistant response
                // $this->messageRepository->logMessage($userId, $targetId, 'user', $message); // User said YES
                $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
                return $response;
            } else {
                $chatConfig['onboarding_step'] = $isNewChatConfig ? 0 : self::ONBOARDING_COMPLETED_STEP; // Revert to previous state or new if it was a fresh config
                $this->db->saveChatConfig($targetId, $chatConfig);
                $response = "Reset cancelled. Let's continue where we left off.";
                 $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
                return $response;
            }
        }

        // Fetch general settings from the database
        $settings = $this->db->getAllSettings();
        $systemPrompt = $settings['systemPrompt'] ?? 'Default fallback system prompt.';
        $model = $settings['model'] ?? 'default-model';
        $userOnboardingQuestions = json_decode($settings['user_onboarding_questions'] ?? '[]', true);
        $groupOnboardingQuestions = json_decode($settings['group_onboarding_questions'] ?? '[]', true);

        // ONBOARDING FLOW
        if ($chatConfig['onboarding_step'] < self::ONBOARDING_COMPLETED_STEP) {
            $response = '';
            switch ($chatConfig['onboarding_step']) {
                case 0: // Ask about mention requirement
                    $response = "Welcome! To start, should I respond to every message in this chat, or only when I'm mentioned (@[BotName])? Reply with 'every' or 'mentioned'.";
                    $chatConfig['onboarding_step'] = 1;
                    break;
                case 1: // Process mention requirement & ask about chat type (single/group)
                    $answer = strtolower(trim($message));
                    if ($answer === 'every') {
                        $chatConfig['requires_mention'] = false;
                    } elseif ($answer === 'mentioned') {
                        $chatConfig['requires_mention'] = true;
                    } else {
                        $response = "Sorry, I didn't understand that. Please reply with 'every' or 'mentioned'.";
                        // Don't advance step, ask again
                        $this->db->saveChatConfig($targetId, $chatConfig);
                        $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
                        return $response;
                    }
                    $response = "Got it. Is this a one-on-one chat with you, or a group chat? Reply with 'one-on-one' or 'group'.";
                    $chatConfig['onboarding_step'] = 2;
                    break;
                case 2: // Process chat type & start specific questions
                    $answer = strtolower(trim($message));
                    if ($answer === 'one-on-one') {
                        $chatConfig['is_group_chat'] = false;
                    } elseif ($answer === 'group') {
                        $chatConfig['is_group_chat'] = true;
                    } else {
                        $response = "Sorry, I didn't understand that. Please reply with 'one-on-one' or 'group'.";
                        $this->db->saveChatConfig($targetId, $chatConfig);
                        $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
                        return $response;
                    }
                    $chatConfig['onboarding_step'] = 3;
                    $chatConfig['current_question_index'] = 0;
                    // Fall through to ask the first question from step 3

                case 3: // Ask custom onboarding questions
                    $questions = $chatConfig['is_group_chat'] ? $groupOnboardingQuestions : $userOnboardingQuestions;
                    $questionIndex = $chatConfig['current_question_index'];

                    if ($questionIndex > 0 && $questionIndex <= count($questions)) {
                        // Save answer to the previous question
                        $onboardingAnswers[$questions[$questionIndex - 1]] = $message; // Store question as key, answer as value
                        $chatConfig['onboarding_answers'] = json_encode($onboardingAnswers);
                    }

                    if ($questionIndex < count($questions)) {
                        $response = $questions[$questionIndex];
                        $chatConfig['current_question_index']++;
                    } else {
                        // All questions asked
                        $response = "Thanks for answering those questions! I'm all set up for this chat.";
                        $chatConfig['onboarding_step'] = self::ONBOARDING_COMPLETED_STEP;
                    }
                    break;
            }
            $this->db->saveChatConfig($targetId, $chatConfig);
            $this->messageRepository->logMessage($userId, $targetId, 'assistant', $response);
            return $response;
        }
        // END ONBOARDING FLOW

        // If bot is not mentioned and it's required for this chat, exit (unless it was a /reset or onboarding message)
        $botMentionSetting = $this->db->getSetting('botMention', 'AI'); // Fallback bot name
        if ($chatConfig['requires_mention'] && stripos($message, '@' . $botMentionSetting) === false) {
            // Do not exit if it was a /reset related message already handled, or during onboarding
             error_log("Bot not mentioned and mention is required. Exiting for targetId: {$targetId}");
            exit; // Or return a silent response, or a hint
        }

        // Inject user info (name only, history is handled separately)
        $injectedSystemPrompt = "Current Time: $currentTime\nUser Name: $userName\nTarget ID (Chat ID): $targetId\n";
        
        // Inject onboarding answers into system prompt
        if (!empty($onboardingAnswers)) {
            $injectedSystemPrompt .= "\n--- Onboarding Information for this Chat ---\n";
            foreach ($onboardingAnswers as $question => $answer) {
                $injectedSystemPrompt .= "Q: " . htmlspecialchars($question) . "\nA: " . htmlspecialchars($answer) . "\n";
            }
            $injectedSystemPrompt .= "---------------------------------------\n";
        }
        $injectedSystemPrompt .= $systemPrompt; // Append the main system prompt
        
        // Apply RAG if available
        $retrievalInfo = null;
        $retrievalResult = null;
        if ($this->retriever != null) {
            error_log("DEBUG - Starting RAG retrieval for message: " . substr($message, 0, 100));
            $retrievalResult = $this->retriever->retrieveRelevantContent($message);
            
            if (!$retrievalResult['success']) {
                error_log("DEBUG - RAG retrieval failed: " . json_encode($retrievalResult));
            } else {
                error_log("DEBUG - RAG retrieval successful with " . 
                    (isset($retrievalResult['matches']) ? count($retrievalResult['matches']) : 0) . " matches");
            }
            
            if ($retrievalResult['success'] && !empty($retrievalResult['matches'])) {
                $injectedSystemPrompt = $this->retriever->augmentPrompt($injectedSystemPrompt, $message, $retrievalResult);
                $retrievalInfo = $retrievalResult;
                error_log("DEBUG - System prompt augmented with RAG content");
            } else {
                error_log("DEBUG - No RAG augmentation applied to system prompt");
            }
        }
        
        // Get formatted message history from the repository
        // If group chat, history might be different (e.g. use targetId as primary key for history retrieval if desired)
        // For now, user_id and target_id are used together to scope history.
        $historyIdentifier = $chatConfig['is_group_chat'] ? $targetId : $userId; // This logic might need refinement on how history is shared/scoped
        // Current UserMessageRepository already scopes by $userId AND $targetId, so no change needed here for history fetching
        $history = $this->messageRepository->getUserMessageHistory($userId, $targetId);
        
        // Prepare messages for API call
        $messages = array_merge(
            [
                ["role" => "system", "content" => $injectedSystemPrompt]
            ],
            $history, // Add formatted history
            // Note: The current user message is already in $history from logMessage/getUserMessageHistory
            // If getUserMessageHistory doesn't include the *very last* message logged,
            // uncomment the line below.
            // [ ["role" => "user", "content" => $message] ]
        );
        
        // Generate response
        $response = $this->llmClient->generateResponse(
            $messages,
            $model, // Use model from DB settings
            0.1
        );
        
        // Extract the assistant's response
      if (isset($response['choices'][0]['message']['content'])) {
            $responseText = $response['choices'][0]['message']['content'];
            
            // Prepend the initial bot response (welcome message) if any
            $finalResponseText = $responseText;

            // Log the assistant's response (the part from LLM)
            $this->messageRepository->logMessage($userId, $targetId, 'assistant', $responseText); // Log only LLM part
            
            // Add debug information if requested
            if ($this->debug && $retrievalInfo !== null) {
                $finalResponseText .= $this->formatRetrievalDebugInfo($retrievalInfo);
            }
            
            // Add detailed debug information about the retrieval process
            if ($this->debug) {
                $debugInfo = "\n\nDEBUG INFO:";
                if ($retrievalResult !== null) {
                    $debugInfo .= "\nRetrieval Result: " . json_encode($retrievalResult, JSON_PRETTY_PRINT);
                } else {
                    $debugInfo .= "\nRetrieval Result: null (No retrieval was performed)";
                }
                $debugInfo .= "\nSystem Prompt Length: " . strlen($injectedSystemPrompt) . " characters";
                $debugInfo .= "\nModel Used: $model"; // Add model info
                $debugInfo .= "\nMessage History Sent (Count: " . count($history) . "): " . json_encode($history, JSON_PRETTY_PRINT); // Log history
                return $finalResponseText . $debugInfo;
            }
            
            return $finalResponseText;
        }
        
        // Handle error
        $errorMessage = "Error in API response. Details logged to server error log.";
        
        if (isset($response['error'])) {
            $errorMessage .= "\n\nAPI Error: " . ($response['error']['message'] ?? 'Unknown error');
        }
        
        return $errorMessage;
    }
    
    private function formatRetrievalDebugInfo(array $retrievalInfo): string {
        $debugInfo = "\n\n---\n[Debug Information - Not Part of Response]\n";
        $debugInfo .= "Retrieved " . count($retrievalInfo['matches']) . " relevant documents:\n";
        
        foreach ($retrievalInfo['matches'] as $index => $match) {
            $similarity = round($match['similarity'] * 100, 2);
            $docId = $match['document_id'];
            $content = substr($match['content'], 0, 100) . (strlen($match['content']) > 100 ? '...' : '');
            
            $debugInfo .= "\n[$index] Document: $docId (Relevance: $similarity%)\n";
            $debugInfo .= "    Content: $content\n";
        }
        
        return $debugInfo;
    }
    
    public function setDebugMode(bool $debug): void {
        $this->debug = $debug;
    }
} 