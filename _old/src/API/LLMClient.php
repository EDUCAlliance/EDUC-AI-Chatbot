<?php

namespace EDUC\API;

use Exception;
use EDUC\Utils\Logger;
use EDUC\Core\Environment;

/**
 * LLM Client for GWDG SAIA API integration
 * Supports dynamic model fetching and all GWDG API endpoints
 */
class LLMClient {
    private string $apiKey;
    private string $chatEndpoint;
    private string $embeddingEndpoint;
    private string $modelsEndpoint;
    private string $documentsEndpoint;
    private array $modelCache = [];
    private int $timeout = 60;
    private int $maxRetries = 3;
    
    public function __construct(
        string $apiKey,
        string $chatEndpoint,
        string $embeddingEndpoint = '',
        string $modelsEndpoint = ''
    ) {
        $this->apiKey = $apiKey;
        $this->chatEndpoint = $chatEndpoint;
        $this->embeddingEndpoint = $embeddingEndpoint ?: str_replace('/chat/completions', '/embeddings', $chatEndpoint);
        $this->modelsEndpoint = $modelsEndpoint ?: str_replace('/chat/completions', '/models', $chatEndpoint);
        $this->documentsEndpoint = str_replace('/chat/completions', '/documents', $chatEndpoint);
        
        if (empty($this->apiKey)) {
            throw new Exception('API key is required');
        }
        
        Logger::info('LLMClient initialized', [
            'chat_endpoint' => $this->chatEndpoint,
            'embedding_endpoint' => $this->embeddingEndpoint,
            'models_endpoint' => $this->modelsEndpoint
        ]);
    }
    
    /**
     * Generate chat response
     */
    public function generateResponse(
        array $messages, 
        string $model = 'meta-llama-3.1-8b-instruct', 
        float $temperature = 0.7,
        array $options = []
    ): array {
        $data = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => 2048,
            'top_p' => 0.9
        ], $options);
        
        Logger::debug('Generating chat response', [
            'model' => $model,
            'message_count' => count($messages),
            'temperature' => $temperature
        ]);
        
        return $this->makeRequest($this->chatEndpoint, $data);
    }
    
    /**
     * Generate embeddings
     */
    public function generateEmbeddings(
        string $text, 
        string $model = 'e5-mistral-7b-instruct'
    ): array {
        $data = [
            'input' => $text,
            'model' => $model,
            'encoding_format' => 'float'
        ];
        
        Logger::debug('Generating embeddings', [
            'model' => $model,
            'text_length' => strlen($text)
        ]);
        
        return $this->makeRequest($this->embeddingEndpoint, $data);
    }
    
    /**
     * Get available models from GWDG API
     */
    public function getAvailableModels(bool $useCache = true): array {
        if ($useCache && !empty($this->modelCache)) {
            return $this->modelCache;
        }
        
        try {
            Logger::info('Fetching available models from GWDG API');
            
            $response = $this->makeRequest($this->modelsEndpoint, [], 'POST');
            
            if (!isset($response['data']) || !is_array($response['data'])) {
                throw new Exception('Invalid models response format');
            }
            
            $models = [];
            foreach ($response['data'] as $model) {
                if (isset($model['id'])) {
                    $models[] = [
                        'id' => $model['id'],
                        'name' => $this->getModelDisplayName($model['id']),
                        'capabilities' => $this->getModelCapabilities($model['id']),
                        'provider' => 'gwdg'
                    ];
                }
            }
            
            $this->modelCache = $models;
            Logger::info('Retrieved models from API', ['count' => count($models)]);
            
            return $models;
            
        } catch (Exception $e) {
            Logger::error('Failed to fetch models from API', [
                'error' => $e->getMessage()
            ]);
            
            // Return fallback models if API fails
            return $this->getFallbackModels();
        }
    }
    
    /**
     * Get model display name based on ID
     */
    private function getModelDisplayName(string $modelId): string {
        $displayNames = [
            'meta-llama-3.1-8b-instruct' => 'Meta Llama 3.1 8B Instruct',
            'meta-llama-3.1-8b-rag' => 'Meta Llama 3.1 8B RAG',
            'llama-3.1-sauerkrautlm-70b-instruct' => 'Llama 3.1 SauerkrautLM 70B',
            'llama-3.3-70b-instruct' => 'Llama 3.3 70B Instruct',
            'gemma-3-27b-it' => 'Google Gemma 3 27B IT',
            'mistral-large-instruct' => 'Mistral Large Instruct',
            'qwen3-32b' => 'Qwen 3 32B',
            'qwen3-235b-a22b' => 'Qwen 3 235B A22B',
            'qwen2.5-coder-32b-instruct' => 'Qwen 2.5 Coder 32B',
            'codestral-22b' => 'Codestral 22B',
            'internvl2.5-8b' => 'InternVL 2.5 8B',
            'qwen-2.5-vl-72b-instruct' => 'Qwen 2.5 VL 72B',
            'qwq-32b' => 'Qwen QwQ 32B',
            'deepseek-r1' => 'DeepSeek R1',
            'e5-mistral-7b-instruct' => 'E5 Mistral 7B (Embeddings)'
        ];
        
        return $displayNames[$modelId] ?? ucwords(str_replace(['-', '_'], ' ', $modelId));
    }
    
    /**
     * Get model capabilities based on ID
     */
    private function getModelCapabilities(string $modelId): array {
        $capabilities = [
            'meta-llama-3.1-8b-instruct' => ['text'],
            'meta-llama-3.1-8b-rag' => ['text', 'arcana'],
            'llama-3.1-sauerkrautlm-70b-instruct' => ['text', 'arcana'],
            'llama-3.3-70b-instruct' => ['text'],
            'gemma-3-27b-it' => ['text', 'image'],
            'mistral-large-instruct' => ['text'],
            'qwen3-32b' => ['text'],
            'qwen3-235b-a22b' => ['text'],
            'qwen2.5-coder-32b-instruct' => ['text', 'code'],
            'codestral-22b' => ['text', 'code'],
            'internvl2.5-8b' => ['text', 'image'],
            'qwen-2.5-vl-72b-instruct' => ['text', 'image'],
            'qwq-32b' => ['reasoning'],
            'deepseek-r1' => ['reasoning'],
            'e5-mistral-7b-instruct' => ['embeddings']
        ];
        
        return $capabilities[$modelId] ?? ['text'];
    }
    
    /**
     * Get fallback models if API is unavailable
     */
    private function getFallbackModels(): array {
        return [
            [
                'id' => 'meta-llama-3.1-8b-instruct',
                'name' => 'Meta Llama 3.1 8B Instruct',
                'capabilities' => ['text'],
                'provider' => 'gwdg'
            ],
            [
                'id' => 'llama-3.3-70b-instruct',
                'name' => 'Llama 3.3 70B Instruct',
                'capabilities' => ['text'],
                'provider' => 'gwdg'
            ],
            [
                'id' => 'gemma-3-27b-it',
                'name' => 'Google Gemma 3 27B IT',
                'capabilities' => ['text', 'image'],
                'provider' => 'gwdg'
            ],
            [
                'id' => 'qwen2.5-coder-32b-instruct',
                'name' => 'Qwen 2.5 Coder 32B',
                'capabilities' => ['text', 'code'],
                'provider' => 'gwdg'
            ],
            [
                'id' => 'deepseek-r1',
                'name' => 'DeepSeek R1',
                'capabilities' => ['reasoning'],
                'provider' => 'gwdg'
            ]
        ];
    }
    
    /**
     * Convert document using Docling API
     */
    public function convertDocument(
        string $filePath,
        string $responseType = 'markdown',
        array $options = []
    ): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }
        
        $url = $this->documentsEndpoint . '/convert';
        
        // Add query parameters
        $queryParams = array_merge([
            'response_type' => $responseType
        ], $options);
        
        $url .= '?' . http_build_query($queryParams);
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120, // Longer timeout for document processing
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => [
                'document' => new \CURLFile($filePath)
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Document conversion failed: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Document conversion API returned status {$httpCode}: {$response}");
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from document conversion API");
        }
        
        Logger::info('Document converted successfully', [
            'file' => basename($filePath),
            'response_type' => $responseType,
            'status_code' => $httpCode
        ]);
        
        return $data;
    }
    
    /**
     * Make HTTP request to API
     */
    private function makeRequest(string $url, array $data = [], string $method = 'POST'): array {
        $retries = 0;
        
        while ($retries < $this->maxRetries) {
            try {
                $ch = curl_init();
                
                $headers = [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                    'Content-Type: application/json'
                ];
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method
                ]);
                
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    throw new Exception("cURL error: {$error}");
                }
                
                $decodedResponse = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON response: " . json_last_error_msg());
                }
                
                if ($httpCode >= 200 && $httpCode < 300) {
                    Logger::debug('API request successful', [
                        'url' => $url,
                        'status_code' => $httpCode,
                        'method' => $method
                    ]);
                    return $decodedResponse;
                }
                
                // Handle specific error codes
                if ($httpCode === 401) {
                    throw new Exception("Authentication failed. Please check your API key.");
                }
                
                if ($httpCode === 429) {
                    // Rate limiting - wait and retry
                    $waitTime = pow(2, $retries); // Exponential backoff
                    Logger::warning("Rate limited, retrying in {$waitTime} seconds", [
                        'attempt' => $retries + 1,
                        'max_retries' => $this->maxRetries
                    ]);
                    sleep($waitTime);
                    $retries++;
                    continue;
                }
                
                if ($httpCode >= 500) {
                    // Server error - retry
                    Logger::warning("Server error, retrying", [
                        'status_code' => $httpCode,
                        'attempt' => $retries + 1,
                        'max_retries' => $this->maxRetries
                    ]);
                    $retries++;
                    sleep(1);
                    continue;
                }
                
                // Client error - don't retry
                $errorMessage = $decodedResponse['error']['message'] ?? 'Unknown error';
                throw new Exception("API error (HTTP {$httpCode}): {$errorMessage}");
                
            } catch (Exception $e) {
                if ($retries >= $this->maxRetries - 1) {
                    Logger::error('API request failed after retries', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                        'retries' => $retries
                    ]);
                    throw $e;
                }
                
                $retries++;
                sleep(1);
            }
        }
        
        throw new Exception("Max retries exceeded");
    }
    
    /**
     * Test API connection
     */
    public function testConnection(): array {
        try {
            $models = $this->getAvailableModels(false);
            
            // Try a simple chat completion
            $testResponse = $this->generateResponse([
                ['role' => 'user', 'content' => 'Hello']
            ], 'meta-llama-3.1-8b-instruct');
            
            return [
                'status' => 'success',
                'models_count' => count($models),
                'test_response' => isset($testResponse['choices'][0]['message']['content'])
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get API usage statistics (if supported)
     */
    public function getUsageStats(): array {
        // This would be implementation-specific based on GWDG API capabilities
        return [
            'requests_today' => 0,
            'tokens_used' => 0,
            'rate_limit_remaining' => 'unknown'
        ];
    }
    
    /**
     * Set request timeout
     */
    public function setTimeout(int $timeout): void {
        $this->timeout = $timeout;
    }
    
    /**
     * Set max retries
     */
    public function setMaxRetries(int $maxRetries): void {
        $this->maxRetries = $maxRetries;
    }
}
?> 