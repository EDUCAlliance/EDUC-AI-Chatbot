<?php
/**
 * EDUC AI TalkBot Enhanced - Nextcloud Webhook Endpoint
 * Main entry point for Nextcloud Talk webhook integration
 * Compatible with Cloudron deployment environment
 */

require_once 'vendor/autoload.php';
require_once __DIR__ . '/auto-include.php'; // Cloudron auto-include

use EDUC\Core\Environment;
use EDUC\Core\Chatbot;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\RAG\Retriever;
use EDUC\Database\EmbeddingRepository;
use EDUC\Utils\Logger;
use EDUC\Utils\Security;

// Initialize logging and error handling
Logger::initialize();
Security::initializeErrorHandlers();

try {
    // Load environment variables (supports both .env files and Cloudron env vars)
    Environment::load();
    
    // Get shared secret for secure bot communication
    $secret = Environment::get('BOT_TOKEN');
    if (empty($secret)) {
        throw new Exception('BOT_TOKEN not configured');
    }
    
    // 1. Receive and validate the webhook
    $inputContent = file_get_contents('php://input');
    if (empty($inputContent)) {
        http_response_code(400);
        exit('No input data received');
    }
    
    $data = json_decode($inputContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        exit('Invalid JSON data');
    }
    
    // 2. Verify the signature for security
    $signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
    $random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';
    
    if (empty($signature) || empty($random)) {
        Logger::error('Missing signature or random headers');
        http_response_code(401);
        exit('Missing authentication headers');
    }
    
    $generatedDigest = hash_hmac('sha256', $random . $inputContent, $secret);
    if (!hash_equals($generatedDigest, strtolower($signature))) {
        Logger::error('Webhook signature verification failed', [
            'expected' => $generatedDigest,
            'received' => $signature
        ]);
        http_response_code(401);
        exit('Authentication failed');
    }
    
    // 3. Extract message data
    $message = $data['object']['content'] ?? '';
    $userName = $data['actor']['name'] ?? 'Unknown User';
    $userId = $data['actor']['id'] ?? '';
    $targetId = $data['target']['id'] ?? '';
    $messageId = $data['object']['id'] ?? '';
    
    if (empty($message) || empty($userId) || empty($targetId)) {
        Logger::warning('Incomplete webhook data received', $data);
        exit('Incomplete data');
    }
    
    Logger::info('Webhook received', [
        'user' => $userName,
        'user_id' => $userId,
        'target_id' => $targetId,
        'message_length' => strlen($message)
    ]);
    
    // 4. Initialize components
    $db = Database::getInstance();
    
    $llmClient = new LLMClient(
        Environment::get('AI_API_KEY'),
        Environment::get('AI_API_ENDPOINT'),
        Environment::get('EMBEDDING_API_ENDPOINT'),
        Environment::get('MODELS_API_ENDPOINT')
    );
    
    // Initialize RAG if enabled
    $retriever = null;
    $useRag = filter_var(Environment::get('USE_RAG', 'true'), FILTER_VALIDATE_BOOLEAN);
    
    if ($useRag) {
        $embeddingRepository = new EmbeddingRepository($db);
        $topK = (int)Environment::get('RAG_TOP_K', 5);
        $retriever = new Retriever($llmClient, $embeddingRepository, $topK);
        Logger::info('RAG system initialized', ['top_k' => $topK]);
    }
    
    // Get debug mode from settings
    $debugMode = $db->getSetting('debug_mode', 'false');
    $debug = filter_var($debugMode, FILTER_VALIDATE_BOOLEAN);
    
    // Initialize the chatbot
    $chatbot = new Chatbot($llmClient, $db, $retriever, $debug);
    
    // 5. Process the message
    $currentTime = date('c');
    $response = $chatbot->processUserMessage($message, $userId, $userName, $targetId, $currentTime);
    
    if (empty($response)) {
        Logger::warning('Empty response generated for message', ['message' => $message]);
        $response = "I apologize, but I couldn't process your message at the moment. Please try again.";
    }
    
    // 6. Send reply to Nextcloud Talk
    $replySuccess = sendReplyToNextcloud($response, $data, $secret, $messageId);
    
    if ($replySuccess) {
        Logger::info('Reply sent successfully', [
            'target_id' => $targetId,
            'response_length' => strlen($response)
        ]);
    } else {
        Logger::error('Failed to send reply to Nextcloud Talk');
    }
    
} catch (Exception $e) {
    Logger::error('Fatal error in webhook processing', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Try to send an error response if we have enough context
    if (isset($data) && isset($secret)) {
        $errorResponse = "I encountered an internal error. Please try again later or contact an administrator.";
        sendReplyToNextcloud($errorResponse, $data, $secret, $messageId ?? 0);
    }
    
    http_response_code(500);
    exit('Internal server error');
}

/**
 * Send a reply message to Nextcloud Talk
 */
function sendReplyToNextcloud(string $message, array $originalData, string $secret, int $replyToId): bool {
    try {
        $token = $originalData['target']['id'];
        $ncUrl = Environment::get('NC_URL');
        
        if (empty($ncUrl)) {
            throw new Exception('NC_URL not configured');
        }
        
        $apiUrl = "https://{$ncUrl}/ocs/v2.php/apps/spreed/api/v1/bot/{$token}/message";
        
        // Generate new random value for signing
        $random = bin2hex(random_bytes(32));
        
        $requestBody = [
            'message' => $message,
            'referenceId' => sha1($random . time()),
            'replyTo' => $replyToId,
        ];
        
        $jsonBody = json_encode($requestBody, JSON_THROW_ON_ERROR);
        $hash = hash_hmac('sha256', $random . $requestBody['message'], $secret);
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'OCS-APIRequest: true',
                'X-Nextcloud-Talk-Bot-Random: ' . $random,
                'X-Nextcloud-Talk-Bot-Signature: ' . $hash,
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            Logger::error('cURL error sending reply', ['error' => $error]);
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            Logger::debug('Reply API response', [
                'status_code' => $httpCode,
                'response' => $response
            ]);
            return true;
        } else {
            Logger::error('Reply API returned error status', [
                'status_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }
        
    } catch (Exception $e) {
        Logger::error('Exception sending reply', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}
?> 