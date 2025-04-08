<?php
require_once 'vendor/autoload.php';

use EDUC\Core\Environment;
use EDUC\Core\Chatbot;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\RAG\Retriever;
use EDUC\RAG\DataProcessor;
use EDUC\Database\EmbeddingRepository;

// Load environment variables
try {
    Environment::load('.env');
} catch (\Exception $e) {
    try {
        Environment::load('/app/code/.env');
    } catch (\Exception $e) {
        error_log("Error loading environment: " . $e->getMessage());
        exit("Error loading environment. Check server logs for details.");
    }
}

// Shared secret for secure bot communication
$secret = Environment::get('BOT_TOKEN');

// 1. Receive the webhook
// Retrieve and decode the incoming JSON payload from the webhook
$inputContent = file_get_contents('php://input');
$data = json_decode($inputContent, true);

// 2. Verify the signature
// Get the signature and random value from the HTTP headers
$signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
$random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';

// Generate an HMAC using the random value and the payload
$generatedDigest = hash_hmac('sha256', $random . $inputContent, $secret);

// Compare the generated digest with the provided signature
if (!hash_equals($generatedDigest, strtolower($signature))) {
    // If the signature is invalid, respond with HTTP 401 Unauthorized and terminate
    http_response_code(401);
    exit;
}

// 3. Extract the message
// Retrieve the message content from the payload
$message = $data['object']['content'];
$name_of_user = $data['actor']['name'];
$id_of_user = $data['actor']['id'];

// Initialize components
try {
    // Initialize the database
    $dbPath = Environment::get('DB_PATH', dirname(__FILE__) . '/database/chatbot.sqlite');
    $db = Database::getInstance($dbPath);
    
    // Initialize the LLM client
    $llmClient = new LLMClient(
        Environment::get('AI_API_KEY'),
        Environment::get('AI_API_ENDPOINT'),
        Environment::get('EMBEDDING_API_ENDPOINT')
    );
    
    // Check if we should use RAG
    $useRag = strtolower(Environment::get('USE_RAG', 'true')) === 'true';
    $debug = strtolower(Environment::get('DEBUG', 'false')) === 'true';
    $retriever = null;
    
    if ($useRag) {
        // Initialize embedding repository and retriever
        $embeddingRepository = new EmbeddingRepository($db);
        
        // Get RAG configuration from environment or use defaults
        $topK = (int)Environment::get('RAG_TOP_K', 5);
        
        // Create custom llm client for embeddings if a different endpoint is specified
        $embeddingEndpoint = Environment::get('EMBEDDING_API_ENDPOINT');
        $embeddingClient = $embeddingEndpoint !== Environment::get('AI_API_ENDPOINT') 
            ? new LLMClient(Environment::get('AI_API_KEY'), Environment::get('AI_API_ENDPOINT'), $embeddingEndpoint)
            : $llmClient;
            
        $retriever = new Retriever($embeddingClient, $embeddingRepository, $topK);
    }
    
    // Initialize the chatbot without config, with debug mode if enabled
    $chatbot = new Chatbot($llmClient, $db, $retriever, $debug);
    
    // Get the bot mention from the database settings
    $botMention = $db->getSetting('botMention', 'defaultBotMention');
    if (empty($botMention)) {
        error_log("Warning: botMention setting not found in database, using fallback.");
        $botMention = 'AI';
    }
    
    // Check if the bot is mentioned
    if (stripos($message, '@' . $botMention) === false) {
        // Exit if the bot is not mentioned
        exit;
    }
    
    // Process the user message
    $llmResponse = $chatbot->processUserMessage($message, $id_of_user, $name_of_user);
    
} catch (\Exception $e) {
    error_log("Error initializing components or processing message: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    $llmResponse = "Sorry, I encountered an internal error. Please try again later or contact an administrator if the problem persists.";
}

// 4. Send a reply to the chat
// Extract the chat room token from the webhook data
$token = $data['target']['id'];

// Define the API URL for sending a bot message to the chat room
$apiUrl = 'https://' . Environment::get('NC_URL') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $token . '/message';

// Prepare the request body with the combined response, a unique reference ID, and the ID of the original message
$requestBody = [
    'message' => $llmResponse,
    'referenceId' => sha1($random), // Unique reference ID for tracking
    'replyTo' => (int)$data['object']['id'], // ID of the original message being replied to
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
    'Content-Type: application/json', // Indicates that the request body is JSON
    'OCS-APIRequest: true', // Required header for Nextcloud API requests
    'X-Nextcloud-Talk-Bot-Random: ' . $random, // Generated random value for signing
    'X-Nextcloud-Talk-Bot-Signature: ' . $hash, // Signature based on the random value and message
]);

// Execute the API request and store the response
$response = curl_exec($ch);

// Check for cURL errors
if(curl_errno($ch)){
    error_log('Curl error in connect.php: ' . curl_error($ch));
}

// Close the cURL session
curl_close($ch);

// Optional: Log or handle the response for debugging purposes
error_log("Reply sent to Nextcloud Talk. API response: " . $response);
?>
