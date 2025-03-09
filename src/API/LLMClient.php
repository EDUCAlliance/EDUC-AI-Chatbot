<?php
namespace EDUC\API;

class LLMClient {
    private string $apiKey;
    private string $endpoint;
    private ?string $embeddingEndpoint;
    private int $maxRetries = 5;
    private int $initialBackoffMs = 1000; // 1 second
    
    public function __construct(string $apiKey, string $endpoint, string $embeddingEndpoint = null) {
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
        $this->embeddingEndpoint = $embeddingEndpoint;
    }
    
    public function generateResponse(array $messages, string $model, float $temperature = 0.1, array $extra = []): array {
        $payload = [
            "model" => $model,
            "messages" => $messages,
            "temperature" => $temperature
        ];
        
        // Add any extra parameters
        if (!empty($extra)) {
            foreach ($extra as $key => $value) {
                $payload[$key] = $value;
            }
        }
        
        return $this->makeApiRequest($payload);
    }
    
    public function generateEmbedding(string $text, string $model = 'e5-mistral-7b-instruct'): array {
        $payload = [
            "model" => $model,
            "input" => $text,
            "encoding_format" => "float"
        ];
        
        // Use the dedicated embedding endpoint if provided, otherwise modify the main endpoint
        $endpoint = null;
        if (!empty($this->embeddingEndpoint)) {
            $endpoint = $this->embeddingEndpoint;
            $pathSuffix = ''; // No suffix needed when using dedicated endpoint
        } else {
            // Fall back to the main endpoint with modification
            $endpoint = preg_replace('#/v1/chat/completions$#', '/v1', $this->endpoint);
            $pathSuffix = '/embeddings';
        }
        
        // Log the endpoint being used for debugging
        error_log("DEBUG - Embedding API Endpoint: " . $endpoint . $pathSuffix);
        
        $response = $this->makeApiRequest($payload, $pathSuffix, $endpoint);
        
        if (isset($response['data'][0]['embedding'])) {
            return [
                'success' => true,
                'embedding' => $response['data'][0]['embedding'],
                'model' => $model
            ];
        }
        
        // Enhanced error reporting
        $errorDetails = '';
        if (isset($response['error'])) {
            $errorDetails = is_array($response['error']) 
                ? json_encode($response['error']) 
                : $response['error'];
        } elseif (isset($response['raw_response'])) {
            $errorDetails = "Raw response: " . $response['raw_response'];
        }
        
        error_log("DEBUG - Embedding generation failed: " . $errorDetails);
        
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error generating embedding',
            'details' => $errorDetails,
            'endpoint' => $endpoint . $pathSuffix
        ];
    }
    
    private function makeApiRequest(array $payload, string $pathSuffix = '', string $customEndpoint = null): array {
        $retries = 0;
        $backoffMs = $this->initialBackoffMs;
        $endpoint = $customEndpoint ?? $this->endpoint;
        
        while ($retries <= $this->maxRetries) {
            $ch = curl_init($endpoint . $pathSuffix);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Authorization: Bearer " . $this->apiKey,
                "Content-Type: application/json"
            ]);
            
            // Encode payload and log for debugging
            $payloadJson = json_encode($payload);
            error_log("DEBUG - API Payload: " . $payloadJson);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            
            // Execute the request and capture any errors
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlInfo = curl_getinfo($ch);
            $httpCode = $curlInfo['http_code'];
            
            error_log("DEBUG - cURL Info: " . json_encode($curlInfo));
            
            if ($response === false) {
                error_log("DEBUG - cURL Error: " . $curlError);
                curl_close($ch);
                
                if ($retries < $this->maxRetries) {
                    // Retry network errors with backoff
                    $retries++;
                    $this->sleep($backoffMs);
                    $backoffMs *= 2; // Exponential backoff
                    error_log("Network error, retrying... (Attempt $retries of {$this->maxRetries})");
                    continue;
                }
                
                return [
                    'error' => "Error executing API request: " . $curlError,
                    'status' => $httpCode
                ];
            }
            
            curl_close($ch);
            
            // Log the raw response for debugging
            error_log("DEBUG - API Raw Response: " . substr($response, 0, 1000));
            
            // Process the API response
            $result = json_decode($response, true);
            
            if ($result === null) {
                error_log("DEBUG - JSON decode error: " . json_last_error_msg());
                
                if ($retries < $this->maxRetries) {
                    $retries++;
                    $this->sleep($backoffMs);
                    $backoffMs *= 2;
                    error_log("Invalid JSON response, retrying... (Attempt $retries of {$this->maxRetries})");
                    continue;
                }
                
                return [
                    'error' => 'Invalid JSON response from API: ' . json_last_error_msg(),
                    'raw_response' => substr($response, 0, 1000),
                    'status' => $httpCode
                ];
            }
            
            // Check for rate limit errors (HTTP 429)
            if ($httpCode === 429 || (isset($result['message']) && strpos($result['message'], 'rate limit') !== false)) {
                if ($retries < $this->maxRetries) {
                    $retries++;
                    // Get retry-after header if available, otherwise use exponential backoff
                    $retryAfter = isset($curlInfo['retry_after']) ? intval($curlInfo['retry_after']) * 1000 : $backoffMs;
                    
                    error_log("Rate limit exceeded, retrying in " . ($retryAfter / 1000) . " seconds... (Attempt $retries of {$this->maxRetries})");
                    $this->sleep($retryAfter);
                    $backoffMs *= 2; // Increase backoff for next retry if needed
                    continue;
                }
                
                error_log("Max retries reached for rate limited request");
            }
            
            // If we get here, we either have a valid response or a non-retryable error
            if (isset($result['error'])) {
                error_log("API Error: " . json_encode($result['error']));
            }
            
            return $result;
        }
        
        // If we exhaust all retries
        return [
            'error' => 'Max retries exceeded',
            'status' => $httpCode ?? 0
        ];
    }
    
    /**
     * Sleep for the specified number of milliseconds
     */
    private function sleep(int $milliseconds): void {
        usleep($milliseconds * 1000);
    }
} 