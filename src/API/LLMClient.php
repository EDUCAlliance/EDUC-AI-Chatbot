<?php
namespace EDUC\API;

class LLMClient {
    private string $apiKey;
    private string $endpoint;
    
    public function __construct(string $apiKey, string $endpoint) {
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
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
        
        $response = $this->makeApiRequest($payload, '/embeddings');
        
        if (isset($response['data'][0]['embedding'])) {
            return [
                'success' => true,
                'embedding' => $response['data'][0]['embedding'],
                'model' => $model
            ];
        }
        
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error generating embedding'
        ];
    }
    
    private function makeApiRequest(array $payload, string $pathSuffix = ''): array {
        $ch = curl_init($this->endpoint . $pathSuffix);
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
        
        error_log("DEBUG - cURL Info: " . json_encode($curlInfo));
        
        if ($response === false) {
            error_log("DEBUG - cURL Error: " . $curlError);
            curl_close($ch);
            return [
                'error' => "Error executing API request: " . $curlError,
                'status' => $curlInfo['http_code']
            ];
        }
        
        curl_close($ch);
        
        // Log the raw response for debugging
        error_log("DEBUG - API Raw Response: " . $response);
        
        // Process the API response
        $result = json_decode($response, true);
        
        if ($result === null) {
            return [
                'error' => 'Invalid JSON response from API',
                'raw_response' => substr($response, 0, 1000),
                'status' => $curlInfo['http_code']
            ];
        }
        
        if (isset($result['error'])) {
            error_log("API Error: " . json_encode($result['error']));
        }
        
        return $result;
    }
} 