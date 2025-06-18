<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use Exception;
use NextcloudBot\Helpers\Logger;

class ApiClient
{
    private string $apiKey;
    private string $apiEndpoint;
    private Logger $logger;

    public function __construct(string $apiKey, string $apiEndpoint, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->apiEndpoint = rtrim($apiEndpoint, '/');
        $this->logger = $logger;
    }

    /**
     * Fetches the list of available models from the AI service.
     *
     * @return array The list of models or an empty array on failure.
     */
    public function getModels(): array
    {
        return $this->makeRequest('POST', '/models');
    }

    /**
     * Generates chat completions based on a conversation history.
     *
     * @param string $model The model to use for the completion.
     * @param array $messages The conversation history messages.
     * @return array The API response.
     */
    public function getChatCompletions(string $model, array $messages): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
        ];
        return $this->makeRequest('POST', '/chat/completions', $body);
    }

    /**
     * Generates an embedding vector for a given text input.
     *
     * @param string $text The text to embed.
     * @param string $model The embedding model to use.
     * @return array The API response containing the embedding.
     */
    public function getEmbedding(string $text, string $model = 'e5-mistral-7b-instruct'): array
    {
        $body = [
            'input' => $text,
            'model' => $model,
            'encoding_format' => 'float',
        ];
        return $this->makeRequest('POST', '/embeddings', $body);
    }

    /**
     * Makes a generic request to the AI API, with rate limiting.
     *
     * @param string $method The HTTP method (GET, POST).
     * @param string $path The API endpoint path (e.g., /chat/completions).
     * @param array|null $body The request body for POST requests.
     * @return array The decoded JSON response.
     */
    private function makeRequest(string $method, string $path, ?array $body = null): array
    {
        $lockFilePath = APP_ROOT . '/cache/api_call.lock';
        $timestampFilePath = APP_ROOT . '/cache/api_call.timestamp';
        
        $lockFileHandle = fopen($lockFilePath, 'c');

        if ($lockFileHandle && flock($lockFileHandle, LOCK_EX)) {
            $this->logger->info('Acquired API call lock.');
            
            try {
                $lastCallTimestamp = (int) @file_get_contents($timestampFilePath);
                $currentTime = time();
                $elapsed = $currentTime - $lastCallTimestamp;
                $minInterval = 5; // 5 seconds

                if ($elapsed < $minInterval) {
                    $sleepTime = $minInterval - $elapsed;
                    if ($sleepTime > 0) {
                        $this->logger->info('Rate limit protection: sleeping for ' . $sleepTime . ' seconds.');
                        sleep($sleepTime);
                    }
                }

                $responseData = $this->executeCurlRequest($method, $path, $body);

                file_put_contents($timestampFilePath, (string)time());
                $this->logger->info('Updated API last call timestamp.');
                
                return $responseData;

            } finally {
                flock($lockFileHandle, LOCK_UN);
                fclose($lockFileHandle);
                $this->logger->info('Released API call lock.');
            }
        } else {
            $this->logger->error('Could not acquire API lock. Proceeding without rate limit protection.');
            if ($lockFileHandle) {
                fclose($lockFileHandle);
            }
            return $this->executeCurlRequest($method, $path, $body);
        }
    }

    /**
     * Executes the actual cURL request to the API.
     *
     * @param string $method The HTTP method.
     * @param string $path The API endpoint path.
     * @param array|null $body The request body.
     * @return array The decoded JSON response.
     */
    private function executeCurlRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->apiEndpoint . $path;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 180-second timeout
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('API Client cURL Error', ['error' => $error, 'url' => $url]);
            return ['error' => "cURL Error: " . $error];
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 400 || isset($responseData['error'])) {
            $this->logger->error('API Client HTTP Error', [
                'code' => $httpCode,
                'response' => $responseData['error'] ?? 'No error message.',
                'url' => $url
            ]);
            return ['error' => $responseData['error'] ?? "HTTP Error {$httpCode}"];
        }

        return $responseData;
    }
} 