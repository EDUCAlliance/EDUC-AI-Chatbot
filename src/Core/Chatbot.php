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
    
    public function processUserMessage(string $message, string $userId, string $userName): string {
        // Log the user message with role 'user'
        $this->messageRepository->logMessage($userId, 'user', $message);
        
        // Fetch settings from the database
        $settings = $this->db->getAllSettings();
        $systemPrompt = $settings['systemPrompt'] ?? 'Default fallback system prompt.'; // Add a fallback
        $model = $settings['model'] ?? 'default-model'; // Add a fallback
        
        // Inject user info (name only, history is handled separately)
        $injectedSystemPrompt = "User Name: $userName\n" . $systemPrompt; // Simplified injection
        
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
        $history = $this->messageRepository->getUserMessageHistory($userId); // Already formatted with roles
        
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
            
            // Log the assistant's response
            $this->messageRepository->logMessage($userId, 'assistant', $responseText);
            
            // Add debug information if requested
            if ($this->debug && $retrievalInfo !== null) {
                $responseText .= $this->formatRetrievalDebugInfo($retrievalInfo);
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
                return $responseText . $debugInfo;
            }
            
            return $responseText;
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