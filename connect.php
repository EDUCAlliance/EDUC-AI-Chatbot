<?php
/**
 * Nextcloud AI Chatbot - Webhook Entry Point
 * 
 * This file serves as the main entry point for Nextcloud Talk webhooks.
 * It validates signatures, processes messages, and manages bot responses.
 */

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load bootstrap
require_once __DIR__ . '/apps/nextcloud-bot/src/bootstrap.php';

use EducBot\Services\WebhookHandler;
use EducBot\Helpers\Logger;

// Set response headers
header('Content-Type: application/json');
header('X-Powered-By: EDUC-AI-Chatbot/1.0');

try {
    // Create webhook handler
    $handler = new WebhookHandler();
    
    // Process the webhook
    $result = $handler->handle();
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook processed successfully',
        'data' => $result
    ]);
    
} catch (\Exception $e) {
    // Log error
    Logger::error('Webhook processing failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
} 