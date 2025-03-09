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
    
    public function processUserMessage(string $message, string $userId, string $userName): string {
        // Log the user message
        $this->messageRepository->logMessage($userId, $message);
        
        // Get the system prompt from config
        $systemPrompt = $this->config->get('systemPrompt', '');
        
        // Inject user info into system prompt
        $injectedSystemPrompt = $this->injectUserInfoIntoSystemPrompt($systemPrompt, $userName, $userId);
        
        // Apply RAG if available
        $retrievalInfo = null;
        $retrievalResult = null;
        if ($this->retriever != null) {
            $retrievalResult = $this->retriever->retrieveRelevantContent($message);
            
            if ($retrievalResult['success'] && !empty($retrievalResult['matches'])) {
                $injectedSystemPrompt = $this->retriever->augmentPrompt($injectedSystemPrompt, $message, $retrievalResult);
                $retrievalInfo = $retrievalResult;
            }
        }
        
        // Prepare messages for API call
        $messages = array_merge(
            [
                ["role" => "system", "content" => $injectedSystemPrompt]
            ],
            $this->config->get('responseExamples', []),
            [
                ["role" => "user", "content" => $message]
            ]
        );
        
        // Generate response
        $response = $this->llmClient->generateResponse(
            $messages,
            $this->config->get('model', ''),
            0.1
        );
        
        // Extract the assistant's response
      if (isset($response['choices'][0]['message']['content'])) {
            $responseText = $response['choices'][0]['message']['content'];
            
            // Add debug information if requested
            if ($this->debug && $retrievalInfo !== null) {
                $responseText .= $this->formatRetrievalDebugInfo($retrievalInfo);
            }
            
            return $responseText."\nDEBUG: ".json_encode($injectedSystemPrompt);
        }
        
        // Handle error
        $errorMessage = "Error in API response. Details logged to server error log.";
        
        if (isset($response['error'])) {
            $errorMessage .= "\n\nAPI Error: " . ($response['error']['message'] ?? 'Unknown error');
        }
        
        return $errorMessage;
    }
    
    private function injectUserInfoIntoSystemPrompt(string $systemPrompt, string $userName, string $userId): string {
        // Retrieve the user's message history
        $history = $this->messageRepository->getUserMessageHistory($userId);
        
        // Build the history string
        $historyString = "\n--- Last " . count($history) . " messages from $userName ---\n";
        foreach ($history as $entry) {
            $historyString .= "[" . $entry['timestamp'] . "] " . $entry['message'] . "\n";
        }
        
        // Inject user info and history into the system prompt
        return "User Name: $userName\n" . $systemPrompt . "\n" . $historyString;
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