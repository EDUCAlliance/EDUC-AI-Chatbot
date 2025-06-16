 ## GUIDE for nextcloud bot
 First of all, you have to install a bot. The term “register” would almost be more appropriate here, as you simply tell Nextcloud to send a webhook to a specific URL after each message (as soon as this bot is activated in the chat) - It is also important to remember the secret token, because the script must encrypt the message with this token in order to be able to respond back

cd /path/to/nextcloud-occ-file && sudo -u www-data php occ talk:bot:install -f webhook,response "Name of the Bot" "XXXX-Secrect-Token-XXXX" "https://Domain-of-BOT.de/script-to-handle-webhook.php" 

If you want to check, if the "installation" of the bot is correct, you can see an list of all bots with this command (you should now also be able to activate the bot in the Conversation settings of an Nextcloud Talk Chat)

sudo -u www-data php occ talk:bot:list 

More OCC Commands can you find here: https://nextcloud-talk.readthedocs.io/en/latest/occ/#talkbotinstall


**IMPORTANT** for handling the webhooks: Here is an example what the php-script gets as a webhook, when a new message is written in a chat, where the bot is activated: {"type":"Create","actor":{"type":"Person","id":"users/admin","name":"admin"},"object":{"type":"Note","id":"182","name":"message","content":"{"message":"tell me about EDUC @educai-test","parameters":[]}","mediaType":"text/markdown"},"target":{"type":"Collection","id":"7fxkpsy6","name":"Notiz an mich"}}



HERE AN EXAMPLE PHP CODE for an Nextcloud bot that can recieve messages, and set messages back:
This two env vars are needed:
BOT_TOKEN=XXXXX  (this is the token from the install command - in the example it was XXXX-Secrect-Token-XXXX)
NC_URL=domain-of-nextcloud-server.de

<?php
require_once 'functions.php';

// Load environment variables
try {
    loadEnv('.env');
} catch (Exception $e) {
    loadEnv('/app/code/.env');
}

// Shared secret for secure bot communication
$secret = getenv('BOT_TOKEN');

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

  
// Load JSON configuration file to get bot mention details
$configContent = file_get_contents(getenv('AI_CONFIG_FILE'));
if ($configContent === false) {
    exit("Error loading LLM config file.");
}
$config = json_decode($configContent, true);
if ($config === null) {
    exit("Error loading LLM config file.");
}

$botMention = $config['botMention'] ?? '';
if (stripos($message, '@' . $botMention) === false) {
    // Exit if the bot is not mentioned
    exit;
}

logUserMessage($id_of_user, $message);

// 4. Send a reply to the chat
// Extract the chat room token from the webhook data
$token = $data['target']['id'];

// Define the API URL for sending a bot message to the chat room
$apiUrl = 'https://' . getenv('NC_URL') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $token . '/message';

// Get the LLM response
//$llmResponse = getLLMResponse($message, getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT'), getenv('AI_CONFIG_FILE')). '/n - History:'. $newSystemPrompt;
$llmResponse = getLLMResponseWithUserHistory($message, getenv('AI_API_KEY'), getenv('AI_API_ENDPOINT'), getenv('AI_CONFIG_FILE'), $name_of_user, $id_of_user);


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

// Close the cURL session
curl_close($ch);

// Optional: Log or handle the response for debugging purposes
?>