<?php

declare(strict_types=1);

// Cron job script for processing the queue.
// This script should be called by a cron job or an external service.

if (file_exists(__DIR__ . '/educ-bootstrap.php')) {
    require_once __DIR__ . '/educ-bootstrap.php';
}

require_once __DIR__ . '/src/bootstrap.php';

use NextcloudBot\Services\ApiClient;
use NextcloudBot\Services\WorkerManager;
use NextcloudBot\Helpers\Logger;

// Prevent this script from being called directly in a browser without context
// and from being run from the command line.
if (php_sapi_name() === 'cli') {
    die("This script is designed to be run via a web server (cron job pointing to a URL).\n");
}

header('Content-Type: text/plain');

$logger = new Logger();
$logger->info('Cron: Received request to process queue.');

try {
    $db = NextcloudBot\getDbConnection();
    $apiClient = new ApiClient(
        NextcloudBot\env('AI_API_KEY'),
        NextcloudBot\env('AI_API_ENDPOINT', 'https://chat-ai.academiccloud.de/v1'),
        $logger
    );
    $workerManager = new WorkerManager($logger, $apiClient, $db);

    $result = $workerManager->processQueue();

    $message = sprintf(
        "Cron: Batch finished. Processed: %d, Failed: %d, Total in batch: %d.",
        $result['processed'],
        $result['failed'],
        $result['total']
    );
    
    $logger->info($message);
    echo $message . "\n";

} catch (\Throwable $e) {
    $logger->error('Cron: A critical error occurred.', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo "Cron: A critical error occurred: " . $e->getMessage() . "\n";
}

echo "Cron: Execution finished.\n"; 