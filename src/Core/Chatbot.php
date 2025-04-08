<?php
namespace EDUC\Core;

use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\UserMessageRepository;
use EDUC\RAG\Retriever;

class Chatbot {
    private Config $config;
    private LLMClient $llmClient;
    private Database $db;
    private UserMessageRepository $messageRepository;
    private ?Retriever $retriever;
    private bool $debug;
    private const MAX_HISTORY_MESSAGES = 20; // Define max history messages
    
    public function __construct(
        Config $config,
        LLMClient $llmClient,
        Database $db,
        ?Retriever $retriever = null,
        bool $debug = false
    ) {
        $this->config = $config;
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->messageRepository = new UserMessageRepository($db);
        $this->retriever = $retriever;
        $this->debug = $debug;
    }
    
    public function processUserMessage(string $messageContent, string $userId, string $userName): string {
        // 1. Log the user's message to history
        $this->messageRepository->logChatMessage($userId, 'user', $messageContent);
        
        // 2. Prepare the initial prompt parts
        $systemPromptContent = $this->config->get('systemPrompt');
        $model = $this->config->get('model');
        
        // 3. Apply RAG augmentation to the system prompt if enabled
        $retrievalInfo = null;
        $retrievalResult = null;
        if ($this->retriever !== null) {
            error_log("DEBUG - Starting RAG retrieval for message: " . substr($messageContent, 0, 100));
            $retrievalResult = $this->retriever->retrieveRelevantContent($messageContent);
            
            if (!$retrievalResult['success']) {
                error_log("DEBUG - RAG retrieval failed: " . json_encode($retrievalResult));
            } else {
                error_log("DEBUG - RAG retrieval successful with " . 
                    (isset($retrievalResult['matches']) ? count($retrievalResult['matches']) : 0) . " matches");
            }
            
            if ($retrievalResult['success'] && !empty($retrievalResult['matches'])) {
                // Augment the system prompt string directly
                $systemPromptContent = $this->retriever->augmentPrompt($systemPromptContent, $messageContent, $retrievalResult);
                $retrievalInfo = $retrievalResult;
                error_log("DEBUG - System prompt augmented with RAG content");
            } else {
                error_log("DEBUG - No RAG augmentation applied to system prompt");
            }
        }
        
        // Add user name info to the system prompt AFTER potential RAG augmentation
        $systemPromptContent = "User Name: {$userName}\nUser ID: {$userId}\n\n" . $systemPromptContent;
        
        // 4. Retrieve chat history
        $chatHistory = $this->messageRepository->getChatHistory($userId, self::MAX_HISTORY_MESSAGES);
        
        // 5. Prepare the final messages array for the API
        $messages = [
            ["role" => "system", "content" => $systemPromptContent]
        ];
        $messages = array_merge($messages, $chatHistory);
        
        // Note: The current user message is already included at the end of $chatHistory
        // Note: responseExamples are no longer included

        // 6. Generate response from LLM
        $responsePayload = $this->llmClient->generateResponse($messages, $model, 0.1);
        
        // 7. Process and log the response
        if (isset($responsePayload['choices'][0]['message']['content'])) {
            $assistantResponseContent = $responsePayload['choices'][0]['message']['content'];
            
            // Log the assistant's response
            $this->messageRepository->logChatMessage($userId, 'assistant', $assistantResponseContent);
            
            // Add debug information if requested
            if ($this->debug) {
                if ($retrievalInfo !== null) {
                    $assistantResponseContent .= $this->formatRetrievalDebugInfo($retrievalInfo);
                }
                // Add other debug info as needed
                $debugDetails = [
                    'retrieval_result' => $retrievalResult, // RAG result
                    'system_prompt_length' => strlen($systemPromptContent),
                    'history_length' => count($chatHistory),
                    'llm_request' => $messages, // What was sent to LLM
                    'llm_response' => $responsePayload // Raw LLM response
                ];
                $assistantResponseContent .= "\n\n--- DEBUG INFO ---\n" . json_encode($debugDetails, JSON_PRETTY_PRINT);
            }
            
            return $assistantResponseContent;
        } else {
            // Handle error
            $errorMessage = "Error in API response. Details logged to server error log.";
            if (isset($responsePayload['error'])) {
                $errorMessage .= "\nAPI Error: " . ($responsePayload['error']['message'] ?? 'Unknown error');
                error_log("LLM API Error: " . json_encode($responsePayload['error']));
            } else {
                error_log("LLM API Error: Unexpected response structure: " . json_encode($responsePayload));
            }
            // Do NOT log this error message itself as an assistant response
            return $errorMessage; // Return error message to be sent back to user
        }
    }
    
    private function formatRetrievalDebugInfo(array $retrievalInfo): string {
        $debugInfo = "\n\n---\n[RAG Debug Info]\n";
        $debugInfo .= "Retrieved " . count($retrievalInfo['matches']) . " relevant documents:\n";
        
        foreach ($retrievalInfo['matches'] as $index => $match) {
            $similarity = round($match['similarity'] * 100, 2);
            $docId = $match['document_id'];
            $content = substr($match['content'], 0, 100) . (strlen($match['content']) > 100 ? '...' : '');
            
            $debugInfo .= "[$index] Doc: $docId (Rel: $similarity%): $content\n";
        }
        
        return $debugInfo;
    }
    
    public function setDebugMode(bool $debug): void {
        $this->debug = $debug;
    }
} 