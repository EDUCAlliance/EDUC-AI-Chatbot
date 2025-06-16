<?php

declare(strict_types=1);

namespace EducBot\Services;

use EducBot\Helpers\Logger;
use Exception;
use RuntimeException;

/**
 * SAIA API Client
 * 
 * Handles communication with the SAIA (Scalable AI Accelerator) API
 * Compatible with OpenAI API standard as documented in AI_API.md
 */
class ApiClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private array $defaultHeaders;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://chat-ai.academiccloud.de/v1',
        int $timeout = 60
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        
        $this->defaultHeaders = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: EDUC-Nextcloud-Chatbot/1.0'
        ];
    }

    /**
     * Get available models from SAIA
     */
    public function getModels(): array
    {
        $startTime = microtime(true);
        
        try {
            $response = $this->makeRequest('GET', '/models');
            
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            Logger::info('Retrieved available models', [
                'model_count' => count($response['data'] ?? []),
                'processing_time_ms' => $processingTime
            ]);
            
            return $response['data'] ?? [];
            
        } catch (Exception $e) {
            Logger::error('Failed to retrieve models', [
                'error' => $e->getMessage(),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000)
            ]);
            throw $e;
        }
    }

    /**
     * Send chat completion request to SAIA
     */
    public function chatCompletion(array $messages, array $options = []): array
    {
        $startTime = microtime(true);
        
        $requestData = [
            'model' => $options['model'] ?? getenv('DEFAULT_MODEL') ?? 'meta-llama-3.1-8b-instruct',
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? (int)getenv('MAX_TOKENS') ?? 512,
            'temperature' => $options['temperature'] ?? (float)getenv('TEMPERATURE') ?? 0.7,
            'top_p' => $options['top_p'] ?? 0.9,
            'stream' => false, // For now, no streaming support
        ];
        
        // Add optional parameters if provided
        if (isset($options['frequency_penalty'])) {
            $requestData['frequency_penalty'] = $options['frequency_penalty'];
        }
        if (isset($options['presence_penalty'])) {
            $requestData['presence_penalty'] = $options['presence_penalty'];
        }
        if (isset($options['seed'])) {
            $requestData['seed'] = $options['seed'];
        }

        try {
            $response = $this->makeRequest('POST', '/chat/completions', $requestData);
            
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            // Extract usage information if available
            $usage = $response['usage'] ?? [];
            $tokensUsed = $usage['total_tokens'] ?? null;
            
            Logger::info('Chat completion successful', [
                'model' => $requestData['model'],
                'message_count' => count($messages),
                'tokens_used' => $tokensUsed,
                'processing_time_ms' => $processingTime
            ]);
            
            // Track API usage
            $this->trackApiUsage('chat/completions', $requestData['model'], $tokensUsed, $processingTime, true);
            
            return $response;
            
        } catch (Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            Logger::error('Chat completion failed', [
                'model' => $requestData['model'],
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);
            
            // Track failed API usage
            $this->trackApiUsage('chat/completions', $requestData['model'], null, $processingTime, false, $e->getMessage());
            
            throw $e;
        }
    }

    /**
     * Generate embeddings for text using SAIA
     */
    public function createEmbedding(string $text, string $model = null): array
    {
        $startTime = microtime(true);
        
        $model = $model ?? getenv('EMBEDDING_MODEL') ?? 'e5-mistral-7b-instruct';
        
        $requestData = [
            'input' => $text,
            'model' => $model,
            'encoding_format' => 'float'
        ];

        try {
            $response = $this->makeRequest('POST', '/embeddings', $requestData);
            
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            Logger::debug('Embedding generation successful', [
                'model' => $model,
                'text_length' => strlen($text),
                'embedding_dimensions' => count($response['data'][0]['embedding'] ?? []),
                'processing_time_ms' => $processingTime
            ]);
            
            // Track API usage
            $usage = $response['usage'] ?? [];
            $tokensUsed = $usage['total_tokens'] ?? null;
            $this->trackApiUsage('embeddings', $model, $tokensUsed, $processingTime, true);
            
            return $response['data'][0]['embedding'] ?? [];
            
        } catch (Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            Logger::error('Embedding generation failed', [
                'model' => $model,
                'text_length' => strlen($text),
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);
            
            // Track failed API usage
            $this->trackApiUsage('embeddings', $model, null, $processingTime, false, $e->getMessage());
            
            throw $e;
        }
    }

    /**
     * Test API connectivity and authentication
     */
    public function testConnection(): array
    {
        try {
            $startTime = microtime(true);
            $models = $this->getModels();
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            return [
                'success' => true,
                'message' => 'API connection successful',
                'model_count' => count($models),
                'processing_time_ms' => $processingTime,
                'available_models' => array_slice($models, 0, 5) // Show first 5 models
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'API connection failed',
                'error' => $e->getMessage(),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000)
            ];
        }
    }

    /**
     * Make HTTP request to SAIA API
     */
    private function makeRequest(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->defaultHeaders,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }
        
        if ($response === false) {
            throw new RuntimeException("HTTP request returned no response");
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response: " . json_last_error_msg());
        }
        
        if ($httpCode >= 400) {
            $errorMessage = $decodedResponse['error']['message'] ?? 
                          $decodedResponse['message'] ?? 
                          "HTTP {$httpCode} error";
            
            throw new RuntimeException("API error (HTTP {$httpCode}): {$errorMessage}");
        }
        
        return $decodedResponse;
    }

    /**
     * Track API usage for analytics
     */
    private function trackApiUsage(
        string $endpoint, 
        ?string $model, 
        ?int $tokensUsed, 
        int $processingTime, 
        bool $success, 
        ?string $errorMessage = null
    ): void {
        try {
            if (!function_exists('getDbConnection')) {
                return;
            }
            
            $db = getDbConnection();
            
            $stmt = $db->prepare('
                INSERT INTO bot_api_usage 
                (endpoint, model, tokens_used, processing_time_ms, success, error_message, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ');
            
            $stmt->execute([
                $endpoint,
                $model,
                $tokensUsed,
                $processingTime,
                $success,
                $errorMessage
            ]);
            
        } catch (Exception $e) {
            Logger::warning('Failed to track API usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get API usage statistics
     */
    public static function getUsageStats(int $days = 7): array
    {
        try {
            if (!function_exists('getDbConnection')) {
                return [];
            }
            
            $db = getDbConnection();
            
            $stmt = $db->prepare('
                SELECT 
                    endpoint,
                    model,
                    COUNT(*) as request_count,
                    SUM(tokens_used) as total_tokens,
                    AVG(processing_time_ms) as avg_processing_time,
                    SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful_requests,
                    COUNT(*) - SUM(CASE WHEN success THEN 1 ELSE 0 END) as failed_requests
                FROM bot_api_usage 
                WHERE created_at >= NOW() - INTERVAL ? DAY
                GROUP BY endpoint, model
                ORDER BY request_count DESC
            ');
            
            $stmt->execute([$days]);
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            Logger::error('Failed to retrieve API usage stats', ['error' => $e->getMessage()]);
            return [];
        }
    }
} 