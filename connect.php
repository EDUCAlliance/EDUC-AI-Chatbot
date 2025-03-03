<?php
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception(".env File not found");
    }

    // read the env file
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // skip lines with an # (comments)
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // seperate at '=' in key and value
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            $value = trim($value, "\"'");

            // set the key
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function getLLMResponse($userMessage, $apiKey, $endpoint, $configFilePath) {
    // loading JSON-LLM-Config File 
    $configContent = file_get_contents($configFilePath);
    if ($configContent === false) {
        return "Error at loading llm config file.";
    }
    $config = json_decode($configContent, true);
    if ($config === null) {
        return "Error at loading llm config file.";
    }
    
    // putting payload together
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
    
    // init cURL 
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
        return "cURL-Error: " . $error;
    }
    curl_close($ch);
    
    // parse JSON-answer
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        return "Error at API answer.";
    }
}

loadEnv('/app/code/.env');

// Shared secret from the bot installation for secure communication
$secret = getenv('BOT_TOKEN');

// 1. Receive the webhook
// Retrieve and decode the incoming JSON payload from the webhook
$data = json_decode(file_get_contents('php://input'), true);

// 2. Verify the signature
// Get the signature and random value sent in the HTTP headers
$signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
$random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';

// Generate a hash-based message authentication code (HMAC) using the random value and the payload
$generatedDigest = hash_hmac('sha256', $random . file_get_contents('php://input'), $secret);

// Compare the generated digest with the signature provided in the request
if (!hash_equals($generatedDigest, strtolower($signature))) {
    // If the signature is invalid, respond with HTTP 401 Unauthorized and terminate
    http_response_code(401);
    exit;
}

// 3. Extract the message
// Retrieve the message content from the payload
$message = $data['object']['content'];

// load JSON config file to get botMention
$configContent = file_get_contents(getenv('AI_CONFIG_FILE'));
if ($configContent === false) {
    exit("Error at loading llm config file.");
}
$config = json_decode($configContent, true);
if ($config === null) {
    exit("Error at loading llm config file.");
}

$botMention = $config['botMention'] ?? '';
if (stripos($message, '@' . $botMention) === false) {
    // exit if bot is not mentioned
    exit;
}

// 4. Send a reply to the chat
// Extract the chat room token from the webhook data
$token = $data['target']['id'];

// Define the API URL for sending a bot message to the chat room
$apiUrl = 'https://' . getenv('NC_URL') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $token . '/message';

// Prepare the request body with the message, a unique reference ID, and the ID of the original message
$requestBody = [
    'message' => getLLMResponse($message, getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT'), getenv('AI_CONFIG_FILE')),
    'referenceId' => sha1($random), // A unique reference ID for tracking
    'replyTo' => (int) $data['object']['id'], // ID of the original message being replied to
];

// Convert the request body to a JSON string
$jsonBody = json_encode($requestBody, JSON_THROW_ON_ERROR);

// Generate a new random value for signing the reply
$random = bin2hex(random_bytes(32));

// Create a signature for the reply message using HMAC
$hash = hash_hmac('sha256', $random . $requestBody['message'], $secret);

// Initialize a cURL session to send the reply via the API
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Set HTTP headers for the API request, including content type and the signature
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json', // Indicate that the request body is JSON
    'OCS-APIRequest: true', // Required header for Nextcloud API requests
    'X-Nextcloud-Talk-Bot-Random: ' . $random, // The generated signature for the response
    'X-Nextcloud-Talk-Bot-Signature: ' . $hash, // The random value used in the signature
]);

// Execute the API request and store the response
$response = curl_exec($ch);

// Close the cURL session
curl_close($ch);

// Optional: Log or handle the response for debugging purposes
?>

?>
