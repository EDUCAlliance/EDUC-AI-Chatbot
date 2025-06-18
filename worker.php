<?php

declare(strict_types=1);

// Worker script for processing queued LLM jobs.
// This should be run as a long-running background process.

require_once __DIR__ . '/src/bootstrap.php';

use NextcloudBot\Services\ApiClient;
use NextcloudBot\Services\WorkerManager;
use NextcloudBot\Helpers\Logger;

// --- Initialization ---
$logger = new Logger();
$db = NextcloudBot\getDbConnection();
$apiClient = new ApiClient(
    NextcloudBot\env('AI_API_KEY'),
    NextcloudBot\env('AI_API_ENDPOINT', 'https://chat-ai.academiccloud.de/v1'),
    $logger
);
$workerManager = new WorkerManager($logger, $apiClient, $db);

$logger->info('Worker started. Entering main loop.');

while (true) {
    try {
        $result = $workerManager->processQueue();
        
        // If no jobs were found, wait before checking again
        if ($result['total'] === 0) {
            sleep(10); // Wait 10 seconds if queue is empty
        } else {
            // If jobs were processed, wait a shorter time
            sleep(2);
        }

    } catch (\Throwable $e) {
        $logger->error('Worker loop encountered a critical error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Avoid busy-looping on critical errors
        sleep(60);
    }
} 