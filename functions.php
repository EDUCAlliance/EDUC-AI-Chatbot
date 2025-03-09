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

  /*  // Check if courses file is specified and load it
    if (isset($config['courses']) && !empty($config['courses'])) {
        $coursesFilePath = $config['courses'];
        error_log("Attempting to load courses from: " . $coursesFilePath);
        $coursesContent = file_get_contents($coursesFilePath);
        if ($coursesContent !== false) {
            error_log("Courses file loaded successfully. Length: " . strlen($coursesContent));
            // Trim the courses content to the first 10000 characters
            $coursesContent = substr($coursesContent, 0, 100000);
            error_log("Courses content trimmed to 10000 characters");
            // Append the courses content to the system prompt
            $config['systemPrompt'] .= " " . $coursesContent;
        } else {
            error_log("Error loading courses file: " . $coursesFilePath . ". Error: " . error_get_last()['message']);
        }
    }*/

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
    
    // Log the endpoint being used
    error_log("DEBUG - Using API endpoint: " . $endpoint);
    error_log("DEBUG - Using model: " . $payload['model']);
    
    // Encode payload and create a debug version for logging
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
        return "Error executing API request: " . $curlError;
    }
    
    curl_close($ch);
    
    // Log the raw response for debugging
    error_log("DEBUG - API Raw Response: " . $response);
    
    // Process the API response.
    $result = json_decode($response, true);
    error_log("DEBUG - API Decoded Response: " . json_encode($result));
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        // Create a detailed error message
        $errorDetails = [
            'Raw Response' => substr($response, 0, 1000), // Limit length to prevent huge logs
            'Decoded Response' => $result,
            'Payload' => $payload,
            'HTTP Status' => $curlInfo['http_code']
        ];
        
        error_log("DEBUG - API Error Details: " . json_encode($errorDetails));
        
        // Get the encoded payload to display
        $payloadExcerpt = substr(json_encode($payload), 0, 300);
        
        // Check for specific error types
        $errorMessage = "Error in API response. Details logged to server error log.";
        
        // Check if there's an error message in the response
        if (isset($result['error'])) {
            $errorMessage .= "\n\nAPI Error: " . $result['error']['message'] ?? 'Unknown error';
            
            // Check for model-related errors
            if (isset($result['error']['code']) && $result['error']['code'] === 'model_not_found') {
                $errorMessage .= "\n\nThe specified model 'meta-llama-3.1-8b-rag' may not be available at this endpoint.";
            }
            
            // Check for authentication errors
            if (isset($result['error']['type']) && $result['error']['type'] === 'invalid_request_error') {
                $errorMessage .= "\n\nThere may be an issue with the Arcana configuration or API key.";
            }
        }
        
        // Add generic troubleshooting tips for Arcana integration
        if (isset($payload['arcana'])) {
            $errorMessage .= "\n\nArcana RAG Integration Troubleshooting:";
            $errorMessage .= "\n- Verify the Arcana ID and key are correct";
            $errorMessage .= "\n- Confirm the API endpoint supports the Arcana integration";
            $errorMessage .= "\n- Check if the model supports RAG functionality";
        }
        
        return $errorMessage . "\n\nStatus code: " . 
               $curlInfo['http_code'] . "\n\nResponse: " . substr($response, 0, 300) . 
               "\n\nPayload (excerpt): " . $payloadExcerpt;
    }
}

/**
 * Logs a user's message into the SQLite database.
 *
 * @param string $userId The ID of the user.
 * @param string $message The message to store.
 * @param string $dbPath Path to the SQLite database file.
 */
function logUserMessage($userId, $message, $dbPath = '/app/code/messages.sqlite') {
    try {
        // Connecting to the SQLite database; if the file doesn't exist, it will be created.
        $db = new PDO("sqlite:" . $dbPath);
        // Initialize the database: create the table if it doesn't exist.
        $db->exec("CREATE TABLE IF NOT EXISTS user_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT,
            message TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $db->prepare("INSERT INTO user_messages (user_id, message) VALUES (:user_id, :message)");
        $stmt->execute([
            ':user_id' => $userId,
            ':message' => $message
        ]);
    } catch (PDOException $e) {
        error_log("SQLite Error: " . $e->getMessage());
    }
}

/**
 * Retrieves the last $limit messages from a specific user from the database.
 *
 * @param string $userId The ID of the user.
 * @param int $limit Number of messages to retrieve.
 * @param string $dbPath Path to the SQLite database file.
 * @return array Array of messages (each with message and timestamp).
 */
function getUserMessageHistory($userId, $limit = 50, $dbPath = '/app/code/messages.sqlite') {
    $messages = [];
    try {
        $db = new PDO("sqlite:" . $dbPath);
        $stmt = $db->prepare("SELECT message, timestamp FROM user_messages WHERE user_id = :user_id ORDER BY id DESC LIMIT :limit");
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Reverse the array to get messages in chronological order.
        $messages = array_reverse($messages);
    } catch (PDOException $e) {
        error_log("SQLite Error: " . $e->getMessage());
    }
    return $messages;
}

/**
 * Integrates the user's name and the last messages into the system prompt.
 *
 * @param string $systemPrompt The original system prompt.
 * @param string $userName The user's name.
 * @param string $userId The user's ID.
 * @return string The modified system prompt with additional information.
 */
function injectUserInfoIntoSystemPrompt($systemPrompt, $userName, $userId) {
    // Retrieve the last 50 messages from the user.
    $history = getUserMessageHistory($userId);
    
    // Build the history string.
    $historyString = "\n--- Last 50 messages from $userName ---\n";
    foreach ($history as $entry) {
        $historyString .= "[" . $entry['timestamp'] . "] " . $entry['message'] . "\n";
    }
    
    // New system prompt: include the user name, the original prompt, and the message history.
    $newSystemPrompt = "User Name: $userName\n" . $systemPrompt . "\n" . $historyString;
    return $newSystemPrompt;
}

/**
 * Example function to retrieve the LLM response with injected user history.
 * This function modifies the system prompt before initiating the API call.
 *
 * @param string $userMessage The message from the user.
 * @param string $apiKey The API key for the LLM.
 * @param string $endpoint The API endpoint.
 * @param string $configFilePath Path to the LLM config file.
 * @param string $userName The user's name.
 * @param string $userId The user's ID.
 * @return string The LLM response.
 */
function getLLMResponseWithUserHistory($userMessage, $apiKey, $endpoint, $configFilePath, $userName, $userId) {
    // Read the configuration file.
    $configContent = file_get_contents($configFilePath);
    if ($configContent === false) {
        return "Error loading LLM config file.";
    }
    $config = json_decode($configContent, true);
    if ($config === null) {
        return "Error loading LLM config file.";
    }
    
    /*    // Check if courses file is specified and load it
    if (isset($config['courses']) && !empty($config['courses'])) {
        $coursesFilePath = $config['courses'];
        error_log("Attempting to load courses from: " . $coursesFilePath);
        $coursesContent = file_get_contents($coursesFilePath);
        if ($coursesContent !== false) {
            error_log("Courses file loaded successfully. Length: " . strlen($coursesContent));
            // Trim the courses content to the first 10000 characters
            $coursesContent = substr($coursesContent, 0, 100000);
            error_log("Courses content trimmed to 10000 characters");
            // Append the courses content to the system prompt
            $config['systemPrompt'] .= " " . $coursesContent;
        } else {
            error_log("Error loading courses file: " . $coursesFilePath . ". Error: " . error_get_last()['message']);
        }
    }*/

    // Inject the user's name and message history into the system prompt.
    $systemPrompt = $config['systemPrompt'] ?? '';
    $injectedSystemPrompt = injectUserInfoIntoSystemPrompt($systemPrompt, $userName, $userId);
    
    // Build the payload for the API request.
    $payload = [
        "model" => $config['model'],
        "messages" => array_merge(
            [
                ["role" => "system", "content" => $injectedSystemPrompt]
            ],
            $config['responseExamples'],
            [
                ["role" => "user", "content" => $userMessage]
            ]
        ),
        "temperature" => 0.1
    ];

    // Add Arcana RAG parameters if configured
    if (isset($config['arcana']) && !empty($config['arcana']['id']) && !empty($config['arcana']['key'])) {
        $payload['extra_body'] = [
            "arcana" => [
                "id" => $config['arcana']['id'],
                "key" => $config['arcana']['key']
            ];
            error_log("Added Arcana RAG parameters for GWDG endpoint");
        } else {
            // For other endpoints like OpenAI, add as extra_body parameter
            // This assumes we're using an intermediate proxy or extended OpenAI-compatible API
            $payload["arcana"] = [
                "id" => $config['arcana']['id'],
                "key" => $config['arcana']['key']
            ];
            error_log("Added Arcana RAG parameters in standard format - caution: endpoint may not support Arcana");
        }
    }

    
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
    
    // Log the endpoint being used
    error_log("DEBUG - Using API endpoint: " . $endpoint);
    error_log("DEBUG - Using model: " . $payload['model']);
    
    // Encode payload and create a debug version for logging
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
        return "Error executing API request: " . $curlError;
    }
    
    curl_close($ch);
    
    // Log the raw response for debugging
    error_log("DEBUG - API Raw Response: " . $response);
    
    // Process the API response.
    $result = json_decode($response, true);
    error_log("DEBUG - API Decoded Response: " . json_encode($result));
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        // Create a detailed error message
        $errorDetails = [
            'Raw Response' => substr($response, 0, 1000), // Limit length to prevent huge logs
            'Decoded Response' => $result,
            'Payload' => $payload,
            'HTTP Status' => $curlInfo['http_code']
        ];
        
        error_log("DEBUG - API Error Details: " . json_encode($errorDetails));
        
        // Get the encoded payload to display
        $payloadExcerpt = substr(json_encode($payload), 0, 300);
        
        // Check for specific error types
        $errorMessage = "Error in API response. Details logged to server error log.";
        
        // Check if there's an error message in the response
        if (isset($result['error'])) {
            $errorMessage .= "\n\nAPI Error: " . $result['error']['message'] ?? 'Unknown error';
            
            // Check for model-related errors
            if (isset($result['error']['code']) && $result['error']['code'] === 'model_not_found') {
                $errorMessage .= "\n\nThe specified model 'meta-llama-3.1-8b-rag' may not be available at this endpoint.";
            }
            
            // Check for authentication errors
            if (isset($result['error']['type']) && $result['error']['type'] === 'invalid_request_error') {
                $errorMessage .= "\n\nThere may be an issue with the Arcana configuration or API key.";
            }
        }
        
        // Add generic troubleshooting tips for Arcana integration
        if (isset($payload['arcana'])) {
            $errorMessage .= "\n\nArcana RAG Integration Troubleshooting:";
            $errorMessage .= "\n- Verify the Arcana ID and key are correct";
            $errorMessage .= "\n- Confirm the API endpoint supports the Arcana integration";
            $errorMessage .= "\n- Check if the model supports RAG functionality";
        }
        
        return $errorMessage . "\n\nStatus code: " . 
               $curlInfo['http_code'] . "\n\nResponse: " . substr($response, 0, 300) . 
               "\n\nPayload (excerpt): " . $payloadExcerpt;
    }
}


?>
