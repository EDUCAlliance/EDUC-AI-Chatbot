<?php
// Loads environment variables from the specified file path
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception(".env File not found");
    }

    // Read the .env file
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip lines that start with '#' (comments)
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Split the line into key and value at the first '=' character
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            $value = trim($value, "\"'");

            // Set the environment variable
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Retrieves the LLM response based on the provided message and configuration
function getLLMResponse($userMessage, $apiKey, $endpoint, $configFilePath) {
    // Load JSON LLM configuration file
    $configContent = file_get_contents($configFilePath);
    if ($configContent === false) {
        return "Error loading LLM config file.";
    }
    $config = json_decode($configContent, true);
    if ($config === null) {
        return "Error loading LLM config file.";
    }
    
    // Construct the payload for the API request
    $payload = [
        "model" => $config['model'],
        "messages" => array_merge(
            [
                ["role" => "system", "content" => $config['systemPrompt']]
            ],
            $config['responseExamples'],
            [
                ["role" => "user", "content" => $userMessage]
            ]
        ),
        "temperature" => 0.1
    ];
    
    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: Bearer " . $apiKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return "cURL Error: " . $error;
    }
    curl_close($ch);
    
    // Parse the JSON response
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        return "Error in API response.";
    }
}
?>
