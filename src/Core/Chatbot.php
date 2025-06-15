<?php

namespace EDUC\Core;

use Exception;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\RAG\Retriever;
use EDUC\Utils\Logger;

/**
 * Main Chatbot class with enhanced features and RAG integration
 */
class Chatbot {
    private LLMClient $llmClient;
    private Database $db;
    private ?Retriever $retriever;
    private bool $debugMode;
    
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
            
            // Get or create chat configuration
            $chatConfig = $this->getChatConfig($targetId);
            
            // Store user message
            $this->storeMessage($userId, $targetId, 'user', $message, $timestamp);
            
            // Check if we're in onboarding mode
            if ($chatConfig['onboarding_step'] > 0) {
                return $this->handleOnboarding($message, $userId, $userName, $targetId, $chatConfig);
            }
            
            // Check if this is a bot mention or group chat requiring mention
            if (!$this->shouldRespond($message, $chatConfig)) {
                return '';
            }
            
            // Clean the message (remove bot mentions)
            $cleanMessage = $this->cleanMessage($message);
            
            // Get conversation history
            $conversationHistory = $this->getConversationHistory($targetId);
            
            // Generate response
            $response = $this->generateResponse($cleanMessage, $conversationHistory, $userId);
            
            // Store bot response
            $this->storeMessage($userId, $targetId, 'assistant', $response, $timestamp);
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error('Error processing message', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'target_id' => $targetId
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
            // Create new chat configuration with onboarding
            $config = [
                'target_id' => $targetId,
                'is_group_chat' => false, // This should be determined from context
                'requires_mention' => true,
                'onboarding_step' => 1,
                'current_question_index' => 0,
                'onboarding_answers' => [],
                'settings' => []
            ];
            
            $this->db->saveChatConfig($targetId, $config);
            
            // Send welcome message and start onboarding
            $welcomeMessage = $this->db->getSetting('welcome_message', 'Hello! I\'m your AI assistant.');
            $firstQuestion = $this->getOnboardingQuestion($config, 0);
            
            return $config;
        }
        
        return $config;
    }
    
    /**
     * Handle onboarding process
     */
    private function handleOnboarding(
        string $message,
        string $userId,
        string $userName,
        string $targetId,
        array $chatConfig
    ): string {
        $answers = $chatConfig['onboarding_answers'];
        $currentQuestionIndex = $chatConfig['current_question_index'];
        
        // Store the answer
        $answers[$currentQuestionIndex] = $message;
        
        // Get onboarding questions
        $questions = $this->getOnboardingQuestions($chatConfig['is_group_chat']);
        
        // Move to next question
        $nextQuestionIndex = $currentQuestionIndex + 1;
        
        if ($nextQuestionIndex < count($questions)) {
            // Ask next question
            $chatConfig['onboarding_answers'] = $answers;
            $chatConfig['current_question_index'] = $nextQuestionIndex;
            $this->db->saveChatConfig($targetId, $chatConfig);
            
            return "Thank you for your answer! " . $questions[$nextQuestionIndex];
        } else {
            // Onboarding complete
            $chatConfig['onboarding_step'] = 0;
            $chatConfig['onboarding_answers'] = $answers;
            $this->db->saveChatConfig($targetId, $chatConfig);
            
            Logger::info('Onboarding completed', [
                'user_id' => $userId,
                'target_id' => $targetId,
                'answers' => $answers
            ]);
            
            return "Thank you for completing the setup! I now have a better understanding of how to assist you. How can I help you today?";
        }
    }
    
    /**
     * Get onboarding questions
     */
    private function getOnboardingQuestions(bool $isGroupChat): array {
        $settingKey = $isGroupChat ? 'group_onboarding_questions' : 'user_onboarding_questions';
        $questionsJson = $this->db->getSetting($settingKey, '[]');
        return json_decode($questionsJson, true) ?: [];
    }
    
    /**
     * Get specific onboarding question
     */
    private function getOnboardingQuestion(array $chatConfig, int $index): string {
        $questions = $this->getOnboardingQuestions($chatConfig['is_group_chat']);
        return $questions[$index] ?? 'How can I help you today?';
    }
    
    /**
     * Check if bot should respond to this message
     */
    private function shouldRespond(string $message, array $chatConfig): bool {
        // Always respond in private chats
        if (!$chatConfig['is_group_chat']) {
            return true;
        }
        
        // In group chats, check if bot is mentioned
        if ($chatConfig['requires_mention']) {
            $botMention = $this->db->getSetting('bot_mention', 'AI Assistant');
            return str_contains(strtolower($message), strtolower('@' . $botMention)) ||
                   str_contains(strtolower($message), strtolower($botMention));
        }
        
        return true;
    }
    
    /**
     * Clean message by removing bot mentions
     */
    private function cleanMessage(string $message): string {
        $botMention = $this->db->getSetting('bot_mention', 'AI Assistant');
        
        // Remove @mentions
        $message = preg_replace('/@' . preg_quote($botMention, '/') . '/i', '', $message);
        $message = preg_replace('/' . preg_quote($botMention, '/') . '/i', '', $message);
        
        return trim($message);
    }
    
    /**
     * Get conversation history
     */
    private function getConversationHistory(string $targetId, int $limit = 10): array {
        $prefix = $this->db->getTablePrefix();
        
        $messages = $this->db->query(
            "SELECT role, content, created_at FROM {$prefix}messages 
             WHERE target_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$targetId, $limit]
        );
        
        // Reverse to get chronological order
        return array_reverse($messages);
    }
    
    /**
     * Generate AI response
     */
    private function generateResponse(string $message, array $conversationHistory, string $userId): string {
        // Build conversation context
        $messages = [];
        
        // Add system prompt
        $systemPrompt = $this->db->getSetting('system_prompt', 'You are a helpful AI assistant.');
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        
        // Add RAG context if available
        if ($this->retriever) {
            try {
                $ragContext = $this->retriever->retrieveRelevantContent($message);
                if (!empty($ragContext)) {
                    $contextPrompt = "Here is some relevant information to help answer the user's question:\n\n" . $ragContext;
                    $messages[] = ['role' => 'system', 'content' => $contextPrompt];
                    
                    if ($this->debugMode) {
                        Logger::debug('RAG context retrieved', [
                            'context_length' => strlen($ragContext),
                            'query' => $message
                        ]);
                    }
                }
            } catch (Exception $e) {
                Logger::warning('RAG retrieval failed', ['error' => $e->getMessage()]);
            }
        }
        
        // Add conversation history
        foreach ($conversationHistory as $historyMessage) {
            if ($historyMessage['role'] === 'user' || $historyMessage['role'] === 'assistant') {
                $messages[] = [
                    'role' => $historyMessage['role'],
                    'content' => $historyMessage['content']
                ];
            }
        }
        
        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // Get model from settings
        $model = $this->db->getSetting('model', 'meta-llama-3.1-8b-instruct');
        
        // Generate response
        $response = $this->llmClient->generateResponse($messages, $model);
        
        if (isset($response['choices'][0]['message']['content'])) {
            $content = trim($response['choices'][0]['message']['content']);
            
            // Add debug information if enabled
            if ($this->debugMode) {
                $debugInfo = "\n\n[Debug - Model: {$model}";
                if ($this->retriever && !empty($ragContext)) {
                    $debugInfo .= ", RAG: enabled";
                }
                $debugInfo .= "]";
                $content .= $debugInfo;
            }
            
            return $content;
        }
        
        throw new Exception('Invalid response from LLM API');
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
     * Reset chat configuration (useful for testing or restarting onboarding)
     */
    public function resetChatConfig(string $targetId): void {
        $this->db->deleteChatConfig($targetId);
        
        // Clear conversation history
        $prefix = $this->db->getTablePrefix();
        $this->db->execute(
            "DELETE FROM {$prefix}messages WHERE target_id = ?",
            [$targetId]
        );
        
        Logger::info('Chat configuration reset', ['target_id' => $targetId]);
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