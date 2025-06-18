<?php

declare(strict_types=1);

namespace NextcloudBot\Helpers;

class TalkHelper
{
    /**
     * Sends a reply message to a Nextcloud Talk conversation.
     *
     * @param string $message The message content to send.
     * @param string $roomToken The token of the room to reply to.
     * @param int $replyToId The ID of the message to reply to.
     * @param string $ncUrl The base URL of the Nextcloud instance.
     * @param string $secret The shared secret for signing the request.
     * @param Logger $logger A logger instance for logging activities.
     * @return bool True on success, false on failure.
     */
    public static function sendReply(string $message, string $roomToken, int $replyToId, string $ncUrl, string $secret, Logger $logger): bool
    {
        $apiUrl = 'https://' . $ncUrl . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $roomToken . '/message';
        $requestBody = [
            'message' => $message,
            'referenceId' => bin2hex(random_bytes(32)), // Use longer reference ID as per docs
            'replyTo' => $replyToId,
            'silent' => false // Explicit silent parameter
        ];
        $jsonBody = json_encode($requestBody);
        $random = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $random . $requestBody['message'], $secret);
        
        $logger->info('Sending reply to Nextcloud', [
            'apiUrl' => $apiUrl,
            'roomToken' => $roomToken,
            'replyToId' => $replyToId,
            'messagePreview' => substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
            'fullMessage' => $message,
            'messageLength' => strlen($message),
            'requestBody' => $requestBody
        ]);
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'OCS-APIRequest: true',
            'X-Nextcloud-Talk-Bot-Random: ' . $random,
            'X-Nextcloud-Talk-Bot-Signature: ' . $hash,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $logger->error('cURL error when sending reply', ['error' => $curlError]);
            return false;
        }
        
        if ($httpCode >= 400) {
            $logger->error('Failed to send reply to Nextcloud', [
                'code' => $httpCode, 
                'response' => $response,
                'requestBody' => $requestBody,
                'jsonBody' => $jsonBody
            ]);
            return false;
        } else {
            $logger->info('Successfully sent reply to Nextcloud.', [
                'httpCode' => $httpCode,
                'messageLength' => strlen($message),
                'response' => $response
            ]);
            return true;
        }
    }
} 