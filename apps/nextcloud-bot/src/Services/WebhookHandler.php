<?php

declare(strict_types=1);

namespace EducBot\Services;

use EducBot\Helpers\Logger;
use EducBot\Services\ApiClient;
use EducBot\Services\VectorStore;
use EducBot\Services\OnboardingManager;
use EducBot\Models\RoomConfig;
use EducBot\Models\Conversation;
use EducBot\Models\Settings;
use Exception;
use RuntimeException;

/**
 * Webhook Handler Service
 * 
 * Processes incoming Nextcloud Talk webhooks with proper security verification,
 * manages conversation flow, onboarding, and RAG integration.
 */
class WebhookHandler
{
    private ApiClient $apiClient;
    private VectorStore $vectorStore;
    private OnboardingManager $onboardingManager;
    private Settings $settings;
    
    public function __construct()
    {
        // Initialize API client
        $apiKey = getenv('AI_API_KEY');
        $apiEndpoint = getenv('AI_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1';
        
        if (empty($apiKey)) {
            throw new RuntimeException('AI_API_KEY environment variable is required');
        }
        
        $this->apiClient = new ApiClient($apiKey, $apiEndpoint);
        $this->vectorStore = new VectorStore();
        $this->onboardingManager = new OnboardingManager();
        $this->settings = new Settings();
    }

    /**
     * Handle incoming webhook request
     */
    public function handle(): array
    {
        try {
            // 1. Receive and validate webhook data
            $webhookData = $this->receiveWebhook();
            
            // 2. Verify signature for security
            $this->verifySignature($webhookData['content'], $webhookData['signature'], $webhookData['random']);
            
            // 3. Extract message information
            $messageData = $this->extractMessageData($webhookData['data']);
            
            // 4. Check if bot should respond to this message
            if (!$this->shouldRespond($messageData)) {
                Logger::debug('Bot should not respond to this message', [
                    'room_token' => $messageData['room_token'],
                    'message' => substr($messageData['message'], 0, 100)
                ]);
                return ['status' => 'ignored', 'reason' => 'Bot not mentioned or wrong mode'];
            }
            
            // 5. Get or create room configuration
            $roomConfig = $this->getRoomConfig($messageData['room_token']);
            
            // 6. Handle onboarding if not completed
            if (!$roomConfig->isOnboardingDone()) {
                return $this->handleOnboarding($messageData, $roomConfig);
            }
            
            // 7. Process the message and generate response
            $response = $this->processMessage($messageData, $roomConfig);
            
            // 8. Send response back to Nextcloud
            $this->sendResponse($response, $messageData, $webhookData['random']);
            
            return [
                'status' => 'success',
                'message' => 'Response sent successfully',
                'response_length' => strlen($response)
            ];
            
        } catch (Exception $e) {
            Logger::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Receive and parse webhook data
     */
    private function receiveWebhook(): array
    {
        $inputContent = file_get_contents('php://input');
        if (empty($inputContent)) {
            throw new RuntimeException('No webhook data received');
        }

        $data = json_decode($inputContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in webhook data: ' . json_last_error_msg());
        }

        $signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
        $random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';

        if (empty($signature) || empty($random)) {
            throw new RuntimeException('Missing required signature headers');
        }

        return [
            'content' => $inputContent,
            'data' => $data,
            'signature' => $signature,
            'random' => $random
        ];
    }

    /**
     * Verify webhook signature for security
     */
    private function verifySignature(string $content, string $signature, string $random): void
    {
        $secret = getenv('BOT_TOKEN');
        if (empty($secret)) {
            throw new RuntimeException('BOT_TOKEN not configured');
        }

        $expectedSignature = hash_hmac('sha256', $random . $content, $secret);
        
        if (!hash_equals($expectedSignature, strtolower($signature))) {
            Logger::warning('Webhook signature verification failed', [
                'expected' => $expectedSignature,
                'received' => strtolower($signature)
            ]);
            throw new RuntimeException('Signature verification failed');
        }

        Logger::debug('Webhook signature verified successfully');
    }

    /**
     * Extract message data from webhook payload
     */
    private function extractMessageData(array $data): array
    {
        $message = $data['object']['content'] ?? '';
        $userName = $data['actor']['name'] ?? 'Unknown User';
        $userId = $data['actor']['id'] ?? '';
        $roomToken = $data['target']['id'] ?? '';
        $messageId = (int)($data['object']['id'] ?? 0);
        
        // Parse the message content (it's JSON encoded)
        $messageJson = json_decode($message, true);
        $actualMessage = $messageJson['message'] ?? $message;

        if (empty($actualMessage) || empty($userId) || empty($roomToken)) {
            throw new RuntimeException('Incomplete webhook data received');
        }

        return [
            'message' => $actualMessage,
            'user_name' => $userName,
            'user_id' => $userId,
            'room_token' => $roomToken,
            'message_id' => $messageId,
            'raw_content' => $message
        ];
    }

    /**
     * Check if the bot should respond to this message
     */
    private function shouldRespond(array $messageData): bool
    {
        $botMention = $this->settings->getBotMention();
        $message = $messageData['message'];
        
        // Check if bot is mentioned
        $isMentioned = stripos($message, $botMention) !== false;
        
        // Get room configuration to check mention mode
        try {
            $roomConfig = $this->getRoomConfig($messageData['room_token']);
            $mentionMode = $roomConfig->getMentionMode();
            
            // If mention mode is 'always', respond to all messages
            // If mention mode is 'on_mention', only respond when mentioned
            return $mentionMode === 'always' || $isMentioned;
            
        } catch (Exception $e) {
            // If room config doesn't exist, default to mention-only mode
            return $isMentioned;
        }
    }

    /**
     * Get or create room configuration
     */
    private function getRoomConfig(string $roomToken): RoomConfig
    {
        try {
            return RoomConfig::findByToken($roomToken);
        } catch (Exception $e) {
            // Room config doesn't exist, create new one
            Logger::info('Creating new room configuration', ['room_token' => $roomToken]);
            return RoomConfig::create($roomToken);
        }
    }

    /**
     * Handle onboarding process
     */
    private function handleOnboarding(array $messageData, RoomConfig $roomConfig): array
    {
        $response = $this->onboardingManager->processMessage(
            $messageData['message'],
            $roomConfig
        );
        
        Logger::info('Onboarding response generated', [
            'room_token' => $messageData['room_token'],
            'stage' => $roomConfig->getOnboardingStage(),
            'response_length' => strlen($response)
        ]);
        
        // Send onboarding response
        $this->sendResponse($response, $messageData, bin2hex(random_bytes(32)));
        
        return [
            'status' => 'onboarding',
            'stage' => $roomConfig->getOnboardingStage(),
            'response' => $response
        ];
    }

    /**
     * Process message and generate AI response
     */
    private function processMessage(array $messageData, RoomConfig $roomConfig): string
    {
        $startTime = microtime(true);
        
        // Store user message in conversation history
        Conversation::create([
            'room_token' => $messageData['room_token'],
            'user_id' => $messageData['user_id'],
            'user_name' => $messageData['user_name'],
            'role' => 'user',
            'content' => $messageData['message']
        ]);

        // Build context for the AI
        $context = $this->buildContext($messageData, $roomConfig);
        
        // Generate AI response
        $response = $this->generateAIResponse($context);
        
        // Store assistant response in conversation history
        Conversation::create([
            'room_token' => $messageData['room_token'],
            'user_id' => 'bot',
            'user_name' => 'EDUC AI',
            'role' => 'assistant',
            'content' => $response,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000)
        ]);

        return $response;
    }

    /**
     * Build context for AI including system prompt, RAG content, and conversation history
     */
    private function buildContext(array $messageData, RoomConfig $roomConfig): array
    {
        $messages = [];
        
        // 1. System prompt
        $systemPrompt = $this->buildSystemPrompt($roomConfig);
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        
        // 2. Add RAG context if available
        $ragContext = $this->getRagContext($messageData['message']);
        if (!empty($ragContext)) {
            $messages[] = [
                'role' => 'system', 
                'content' => "Here is relevant information from your knowledge base:\n\n" . $ragContext
            ];
        }
        
        // 3. Add conversation history (last 10 messages)
        $history = Conversation::getHistory($messageData['room_token'], 10);
        foreach ($history as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }
        
        // 4. Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $messageData['message']
        ];
        
        return $messages;
    }

    /**
     * Build system prompt including bot configuration and onboarding context
     */
    private function buildSystemPrompt(RoomConfig $roomConfig): string
    {
        $basePrompt = $this->settings->getSystemPrompt();
        
        // Add room-specific context from onboarding
        $onboardingContext = $roomConfig->getOnboardingContext();
        if (!empty($onboardingContext)) {
            $basePrompt .= "\n\nAdditional context about this conversation:\n" . $onboardingContext;
        }
        
        // Add custom room prompt if exists
        $customPrompt = $roomConfig->getCustomPrompt();
        if (!empty($customPrompt)) {
            $basePrompt .= "\n\nCustom instructions for this room:\n" . $customPrompt;
        }
        
        return $basePrompt;
    }

    /**
     * Get relevant context from RAG vector store
     */
    private function getRagContext(string $message): string
    {
        try {
            // Generate embedding for the user message
            $embedding = $this->apiClient->createEmbedding($message);
            
            // Search for similar content
            $topK = $this->settings->getTopK();
            $similarContent = $this->vectorStore->searchSimilar($embedding, $topK);
            
            if (empty($similarContent)) {
                return '';
            }
            
            // Combine similar content into context
            $context = '';
            foreach ($similarContent as $content) {
                $context .= "Document: {$content['filename']}\n";
                $context .= $content['text_content'] . "\n\n";
            }
            
            Logger::debug('RAG context retrieved', [
                'chunks_found' => count($similarContent),
                'context_length' => strlen($context)
            ]);
            
            return trim($context);
            
        } catch (Exception $e) {
            Logger::warning('Failed to retrieve RAG context', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Generate AI response using the API client
     */
    private function generateAIResponse(array $messages): string
    {
        $options = [
            'model' => $this->settings->getDefaultModel(),
            'max_tokens' => $this->settings->getMaxTokens(),
            'temperature' => $this->settings->getTemperature()
        ];

        $response = $this->apiClient->chatCompletion($messages, $options);
        
        $content = $response['choices'][0]['message']['content'] ?? '';
        
        if (empty($content)) {
            throw new RuntimeException('Empty response from AI API');
        }
        
        return trim($content);
    }

    /**
     * Send response back to Nextcloud Talk
     */
    private function sendResponse(string $message, array $messageData, string $random): void
    {
        $ncUrl = getenv('NC_URL');
        if (empty($ncUrl)) {
            throw new RuntimeException('NC_URL not configured');
        }

        $secret = getenv('BOT_TOKEN');
        $apiUrl = "https://{$ncUrl}/ocs/v2.php/apps/spreed/api/v1/bot/{$messageData['room_token']}/message";
        
        $requestBody = [
            'message' => $message,
            'referenceId' => sha1($random . time()),
            'replyTo' => $messageData['message_id']
        ];
        
        $jsonBody = json_encode($requestBody, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $random . $requestBody['message'], $secret);
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'OCS-APIRequest: true',
                'X-Nextcloud-Talk-Bot-Random: ' . $random,
                'X-Nextcloud-Talk-Bot-Signature: ' . $signature,
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new RuntimeException("Failed to send response: {$error}");
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error('Failed to send response to Nextcloud', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new RuntimeException("HTTP {$httpCode} error sending response");
        }
        
        Logger::info('Response sent successfully', [
            'room_token' => $messageData['room_token'],
            'http_code' => $httpCode,
            'message_length' => strlen($message)
        ]);
    }
} 