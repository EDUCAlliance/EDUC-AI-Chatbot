<?php

namespace EDUC\Core;

use Exception;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\RAG\Retriever;
use EDUC\Utils\Logger;

/**
 * Main Chatbot class with enhanced onboarding, reset functionality, and proper message history
 */
class Chatbot {
    private LLMClient $llmClient;
    private Database $db;
    private ?Retriever $retriever;
    private bool $debugMode;
    
    // Onboarding constants
    private const ONBOARDING_COMPLETED_STEP = 4;
    private const RESET_CONFIRMATION_AWAIT_STEP = -1;
    private const ONBOARDING_RESET_CONFIRMATION_MESSAGE = "Are you sure you want to reset all configurations and message history for this chat? This cannot be undone. Please answer with \"YES\" to confirm, or anything else to cancel.";
    
    public function __construct(
        LLMClient $llmClient,
        Database $db,
        ?Retriever $retriever = null,
        bool $debugMode = false
    ) {
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->retriever = $retriever;
        $this->debugMode = $debugMode;
    }
    
    /**
     * Process user message and generate response
     */
    public function processUserMessage(
        string $message,
        string $userId,
        string $userName,
        string $targetId,
        string $timestamp
    ): string {
        try {
            Logger::info('Processing user message', [
                'user_id' => $userId,
                'user_name' => $userName,
                'target_id' => $targetId,
                'message_length' => strlen($message)
            ]);
            
            // Parse the incoming message (might be JSON from Nextcloud Talk)
            $actualUserMessageText = $message;
            $decodedContent = json_decode($message, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decodedContent['message'])) {
                $actualUserMessageText = $decodedContent['message'];
            }
            
            // Store user message first
            $this->storeMessage($userId, $targetId, 'user', $actualUserMessageText, $timestamp);
            
            // Get or create chat configuration
            $chatConfig = $this->getChatConfig($targetId);
            $isNewChatConfig = $chatConfig['onboarding_step'] === 0;
            
            // Handle ((RESET)) command
            if (strtolower(trim($actualUserMessageText)) === '((reset))') {
                return $this->handleResetCommand($chatConfig, $userId, $targetId, $timestamp, $isNewChatConfig);
            }
            
            // Handle reset confirmation
            if ($chatConfig['onboarding_step'] === self::RESET_CONFIRMATION_AWAIT_STEP) {
                return $this->handleResetConfirmation($actualUserMessageText, $chatConfig, $userId, $targetId, $timestamp, $isNewChatConfig);
            }
            
            // Handle onboarding flow - including initial step 0
            if ($chatConfig['onboarding_step'] < self::ONBOARDING_COMPLETED_STEP) {
                return $this->handleOnboarding($actualUserMessageText, $userId, $userName, $targetId, $chatConfig, $timestamp);
            }
            
            // Check if onboarding is complete and handle mention requirements
            if ($chatConfig['onboarding_step'] === self::ONBOARDING_COMPLETED_STEP) {
                if (!$this->shouldRespond($actualUserMessageText, $chatConfig)) {
                    Logger::info('Bot not mentioned and mention required', ['target_id' => $targetId]);
                    return ''; // Silent response when not mentioned in group chat
                }
            }
            
            // Generate AI response
            return $this->generateChatResponse($actualUserMessageText, $userId, $userName, $targetId, $chatConfig, $timestamp);
            
        } catch (Exception $e) {
            Logger::error('Error processing message', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'target_id' => $targetId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return "I apologize, but I encountered an error processing your message. Please try again.";
        }
    }
    
    /**
     * Get or create chat configuration
     */
    private function getChatConfig(string $targetId): array {
        $config = $this->db->getChatConfig($targetId);
        
        if (!$config) {
            // Create new chat configuration - start onboarding
            $config = [
                'target_id' => $targetId,
                'is_group_chat' => false, // Will be determined during onboarding
                'requires_mention' => true, // Will be determined during onboarding
                'onboarding_step' => 0, // Will be set to 1 to start onboarding
                'current_question_index' => 0,
                'onboarding_answers' => [],
                'settings' => []
            ];
            
            $this->db->saveChatConfig($targetId, $config);
        }
        
        return $config;
    }
    
    /**
     * Handle reset command
     */
    private function handleResetCommand(array $chatConfig, string $userId, string $targetId, string $timestamp, bool $isNewChatConfig): string {
        // Check if already awaiting confirmation
        if ($chatConfig['onboarding_step'] !== self::RESET_CONFIRMATION_AWAIT_STEP) {
            $chatConfig['onboarding_step'] = self::RESET_CONFIRMATION_AWAIT_STEP;
            $this->db->saveChatConfig($targetId, $chatConfig);
            $response = self::ONBOARDING_RESET_CONFIRMATION_MESSAGE;
            $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
            return $response;
        } else {
            $response = "Still awaiting confirmation for reset. " . self::ONBOARDING_RESET_CONFIRMATION_MESSAGE;
            $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
            return $response;
        }
    }
    
    /**
     * Handle reset confirmation
     */
    private function handleResetConfirmation(string $message, array $chatConfig, string $userId, string $targetId, string $timestamp, bool $isNewChatConfig): string {
        if (strtoupper(trim($message)) === 'YES') {
            // Perform reset
            $this->resetChatConfig($targetId);
            $response = "Configuration and message history for this chat have been reset. We can start fresh now! Send any message to begin onboarding.";
            $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
            return $response;
        } else {
            // Cancel reset
            $previousState = $isNewChatConfig ? 0 : self::ONBOARDING_COMPLETED_STEP;
            $existingConfigCheck = $this->db->getChatConfig($targetId);
            
            if ($existingConfigCheck === null) {
                $response = "Reset was already processed or chat is new. Send any message to begin onboarding.";
            } else {
                $chatConfig['onboarding_step'] = $previousState;
                $this->db->saveChatConfig($targetId, $chatConfig);
                $response = "Reset cancelled. Let's continue where we left off.";
            }
            
            $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
            return $response;
        }
    }
    
    /**
     * Handle onboarding process
     */
    private function handleOnboarding(
        string $message,
        string $userId,
        string $userName,
        string $targetId,
        array $chatConfig,
        string $timestamp
    ): string {
        $response = '';
        $onboardingAnswers = is_array($chatConfig['onboarding_answers']) ? $chatConfig['onboarding_answers'] : [];
        
        // Get settings for onboarding questions
        $settings = $this->db->getAllSettings();
        $userOnboardingQuestions = json_decode($settings['user_onboarding_questions']['value'] ?? '[]', true);
        $groupOnboardingQuestions = json_decode($settings['group_onboarding_questions']['value'] ?? '[]', true);
        
        switch ($chatConfig['onboarding_step']) {
            case 0: // Start onboarding - ask about mention requirement
                $response = "Welcome! To start, should I respond to every message in this chat, or only when I'm mentioned (@[BotName])? Reply with 'every' or 'mentioned'.";
                $chatConfig['onboarding_step'] = 1;
                break;
                
            case 1: // Process mention requirement & ask about chat type
                $answer = strtolower(trim($message));
                if (str_contains($answer, 'every')) {
                    $chatConfig['requires_mention'] = false;
                } elseif (str_contains($answer, 'mentioned')) {
                    $chatConfig['requires_mention'] = true;
                } else {
                    $response = "Sorry, I didn't understand that. Please reply with 'every' or 'mentioned'.";
                    $this->db->saveChatConfig($targetId, $chatConfig);
                    $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
                    return $response;
                }
                
                $response = "Got it. Is this a one-on-one chat with you, or a group chat? Reply with 'one-on-one' or 'group'.";
                $chatConfig['onboarding_step'] = 2;
                break;
                
            case 2: // Process chat type & start custom questions
                $answer = strtolower(trim($message));
                if (str_contains($answer, 'one-on-one')) {
                    $chatConfig['is_group_chat'] = false;
                } elseif (str_contains($answer, 'group')) {
                    $chatConfig['is_group_chat'] = true;
                } else {
                    $response = "Sorry, I didn't understand that. Please reply with 'one-on-one' or 'group'.";
                    $this->db->saveChatConfig($targetId, $chatConfig);
                    $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
                    return $response;
                }
                
                $chatConfig['onboarding_step'] = 3;
                $chatConfig['current_question_index'] = 0;
                // Fall through to ask the first custom question
                
            case 3: // Ask custom onboarding questions
                $questions = $chatConfig['is_group_chat'] ? $groupOnboardingQuestions : $userOnboardingQuestions;
                $questionIndex = $chatConfig['current_question_index'];
                
                // Save answer to previous question if we're past the first question
                if ($questionIndex > 0 && $questionIndex <= count($questions)) {
                    $onboardingAnswers[$questions[$questionIndex - 1]] = $message;
                    $chatConfig['onboarding_answers'] = $onboardingAnswers;
                }
                
                if ($questionIndex < count($questions)) {
                    $response = $questions[$questionIndex];
                    $chatConfig['current_question_index']++;
                } else {
                    // All questions asked - complete onboarding
                    $response = "Thanks for answering those questions! I'm all set up for this chat.";
                    $chatConfig['onboarding_step'] = self::ONBOARDING_COMPLETED_STEP;
                }
                break;
        }
        
        $this->db->saveChatConfig($targetId, $chatConfig);
        $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
        return $response;
    }
    
    /**
     * Check if bot should respond to this message
     */
    private function shouldRespond(string $message, array $chatConfig): bool {
        // Always respond in one-on-one chats
        if (!$chatConfig['is_group_chat']) {
            return true;
        }
        
        // In group chats, check mention requirement
        if ($chatConfig['requires_mention']) {
            $botMention = $this->db->getSetting('bot_mention', 'AI Assistant');
            return stripos($message, '@' . $botMention) !== false;
        }
        
        return true;
    }
    
    /**
     * Generate AI response for normal chat
     */
    private function generateChatResponse(
        string $message,
        string $userId,
        string $userName,
        string $targetId,
        array $chatConfig,
        string $timestamp
    ): string {
        // Get settings
        $settings = $this->db->getAllSettings();
        $systemPrompt = $settings['system_prompt']['value'] ?? 'You are a helpful AI assistant.';
        $model = $settings['model']['value'] ?? 'meta-llama-3.1-8b-instruct';
        
        // Build system prompt with context
        $injectedSystemPrompt = "Current Time: $timestamp\nUser Name: $userName\nTarget ID (Chat ID): $targetId\n";
        
        // Add onboarding answers to system prompt
        $onboardingAnswers = is_array($chatConfig['onboarding_answers']) ? $chatConfig['onboarding_answers'] : [];
        if (!empty($onboardingAnswers)) {
            $injectedSystemPrompt .= "\n--- Onboarding Information for this Chat ---\n";
            foreach ($onboardingAnswers as $question => $answer) {
                $injectedSystemPrompt .= "Q: " . htmlspecialchars($question) . "\nA: " . htmlspecialchars($answer) . "\n";
            }
            $injectedSystemPrompt .= "---------------------------------------\n";
        }
        $injectedSystemPrompt .= $systemPrompt;
        
        // Apply RAG if available
        $retrievalInfo = null;
        if ($this->retriever) {
            try {
                $retrievalResult = $this->retriever->retrieveRelevantContent($message);
                if ($retrievalResult['success'] && !empty($retrievalResult['matches'])) {
                    $injectedSystemPrompt = $this->retriever->augmentPrompt($injectedSystemPrompt, $message, $retrievalResult);
                    $retrievalInfo = $retrievalResult;
                    Logger::debug('RAG content retrieved and applied', ['matches' => count($retrievalResult['matches'])]);
                }
            } catch (Exception $e) {
                Logger::warning('RAG retrieval failed', ['error' => $e->getMessage()]);
            }
        }
        
        // Get conversation history (scoped correctly for group vs individual chats)
        $history = $this->getConversationHistory($userId, $targetId, $chatConfig['is_group_chat']);
        
        // Build messages for API
        $messages = [
            ['role' => 'system', 'content' => $injectedSystemPrompt]
        ];
        
        // Add history
        $messages = array_merge($messages, $history);
        
        // Generate response
        $response = $this->llmClient->generateResponse($messages, $model, 0.1);
        
        if (isset($response['choices'][0]['message']['content'])) {
            $responseText = $response['choices'][0]['message']['content'];
            
            // Store assistant response
            $this->storeMessage($userId, $targetId, 'assistant', $responseText, $timestamp);
            
            // Add debug information if enabled
            if ($this->debugMode) {
                $debugInfo = "\n\n[Debug - Model: {$model}";
                if ($retrievalInfo !== null) {
                    $debugInfo .= ", RAG: " . count($retrievalInfo['matches']) . " matches";
                }
                $debugInfo .= ", History: " . count($history) . " messages]";
                return $responseText . $debugInfo;
            }
            
            return $responseText;
        }
        
        throw new Exception('Invalid response from LLM API');
    }
    
    /**
     * Get conversation history with proper scoping
     */
    private function getConversationHistory(string $userId, string $targetId, bool $isGroupChat, int $limit = 30): array {
        $prefix = $this->db->getTablePrefix();
        
        if ($isGroupChat) {
            // For group chats, get history for the entire group (by target_id)
            $messages = $this->db->query(
                "SELECT role, content, created_at FROM {$prefix}messages 
                 WHERE target_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$targetId, $limit]
            );
        } else {
            // For one-on-one chats, get history for the specific user and target
            $messages = $this->db->query(
                "SELECT role, content, created_at FROM {$prefix}messages 
                 WHERE user_id = ? AND target_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$userId, $targetId, $limit]
            );
        }
        
        // Format for API and reverse to chronological order
        $formattedHistory = [];
        foreach (array_reverse($messages) as $msg) {
            // Skip the current user message (it's already included as the current message)
            if ($msg['role'] === 'user' || $msg['role'] === 'assistant') {
                $formattedHistory[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }
        
        return $formattedHistory;
    }
    
    /**
     * Store message in database
     */
    private function storeMessage(string $userId, string $targetId, string $role, string $content, string $timestamp): void {
        $prefix = $this->db->getTablePrefix();
        
        $this->db->execute(
            "INSERT INTO {$prefix}messages (user_id, target_id, role, content, created_at) 
             VALUES (?, ?, ?, ?, ?)",
            [$userId, $targetId, $role, $content, $timestamp]
        );
    }
    
    /**
     * Reset chat configuration and clear message history
     */
    public function resetChatConfig(string $targetId): void {
        // Delete chat configuration
        $this->db->deleteChatConfig($targetId);
        
        // Clear conversation history for this target
        $prefix = $this->db->getTablePrefix();
        $this->db->execute(
            "DELETE FROM {$prefix}messages WHERE target_id = ?",
            [$targetId]
        );
        
        Logger::info('Chat configuration and history reset', ['target_id' => $targetId]);
    }
    
    /**
     * Get chat statistics
     */
    public function getChatStats(string $targetId): array {
        $prefix = $this->db->getTablePrefix();
        
        $stats = [];
        
        // Message count
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM {$prefix}messages WHERE target_id = ?",
            [$targetId]
        );
        $stats['total_messages'] = $result[0]['count'] ?? 0;
        
        // Messages by role
        $result = $this->db->query(
            "SELECT role, COUNT(*) as count FROM {$prefix}messages 
             WHERE target_id = ? GROUP BY role",
            [$targetId]
        );
        
        foreach ($result as $row) {
            $stats['messages_by_role'][$row['role']] = $row['count'];
        }
        
        // First and last message timestamps
        $result = $this->db->query(
            "SELECT MIN(created_at) as first_message, MAX(created_at) as last_message 
             FROM {$prefix}messages WHERE target_id = ?",
            [$targetId]
        );
        
        if ($result && $result[0]['first_message']) {
            $stats['first_message'] = $result[0]['first_message'];
            $stats['last_message'] = $result[0]['last_message'];
        }
        
        return $stats;
    }
    
    /**
     * Enable or disable debug mode
     */
    public function setDebugMode(bool $enabled): void {
        $this->debugMode = $enabled;
    }
    
    /**
     * Get current debug mode status
     */
    public function isDebugMode(): bool {
        return $this->debugMode;
    }
}
?> 