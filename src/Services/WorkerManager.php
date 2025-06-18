<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use NextcloudBot\Helpers\Logger;
use NextcloudBot\Helpers\TalkHelper;
use \PDO;

class WorkerManager
{
    private Logger $logger;
    private ApiClient $apiClient;
    private PDO $db;

    private string $pendingDir;
    private string $processingDir;
    private string $completedDir;
    private string $failedDir;

    public function __construct(Logger $logger, ApiClient $apiClient, PDO $db)
    {
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->db = $db;

        $baseDir = APP_ROOT . '/cache/queue/';
        $this->pendingDir = $baseDir . 'pending/';
        $this->processingDir = $baseDir . 'processing/';
        $this->completedDir = $baseDir . 'completed/';
        $this->failedDir = $baseDir . 'failed/';
    }

    public function processQueue(): array
    {
        $this->logger->info('Starting queue processing batch.');
        $processed = 0;
        $failed = 0;

        $jobFiles = glob($this->pendingDir . '*.json');

        if (empty($jobFiles)) {
            $this->logger->info('No pending jobs found.');
            return ['processed' => $processed, 'failed' => $failed, 'total' => 0];
        }

        $this->logger->info('Found ' . count($jobFiles) . ' pending jobs.');

        foreach ($jobFiles as $jobFile) {
            $jobId = basename($jobFile);
            $processingPath = $this->processingDir . $jobId;

            if (!rename($jobFile, $processingPath)) {
                $this->logger->warning('Could not move job to processing directory', ['job' => $jobId]);
                continue;
            }

            $this->logger->info('Processing job', ['jobId' => $jobId]);
            $jobData = json_decode(file_get_contents($processingPath), true);

            if ($jobData === null) {
                $this->logger->error('Failed to decode job data', ['jobId' => $jobId]);
                rename($processingPath, $this->failedDir . $jobId);
                $failed++;
                continue;
            }

            try {
                $llmResponse = $this->apiClient->getChatCompletions($jobData['model'], $jobData['messages']);
                
                if (isset($llmResponse['error'])) {
                     throw new \Exception('LLM API returned an error: ' . $llmResponse['error']);
                }

                $replyContent = $llmResponse['choices'][0]['message']['content'] ?? 'Sorry, I encountered an error and cannot reply right now.';
                $this->logger->info('LLM response received successfully', ['jobId' => $jobId]);

                $stmt = $this->db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content, bot_id) VALUES (?, ?, 'assistant', ?, ?)");
                $stmt->execute([$jobData['roomToken'], 'assistant', $replyContent, $jobData['botId']]);

                $success = TalkHelper::sendReply(
                    $replyContent,
                    $jobData['roomToken'],
                    $jobData['replyToId'],
                    $jobData['ncUrl'],
                    $jobData['secret'],
                    $this->logger
                );

                if (!$success) {
                    throw new \Exception('Failed to send final reply to Nextcloud');
                }

                rename($processingPath, $this->completedDir . $jobId);
                $this->logger->info('Job completed successfully', ['jobId' => $jobId]);
                $processed++;

            } catch (\Throwable $e) {
                $this->logger->error('Job failed during execution', [
                    'jobId' => $jobId,
                    'error' => $e->getMessage()
                ]);
                rename($processingPath, $this->failedDir . $jobId);
                file_put_contents($this->failedDir . $jobId, json_encode(['error' => $e->getMessage(), 'error_time' => date('Y-m-d H:i:s')] + $jobData), FILE_APPEND);
                $failed++;
            }
        }
        
        $this->logger->info('Queue processing batch finished.', ['processed' => $processed, 'failed' => $failed]);
        return ['processed' => $processed, 'failed' => $failed, 'total' => count($jobFiles)];
    }

    public function getQueueStats(): array
    {
        return [
            'pending' => count(glob($this->pendingDir . '*.json')),
            'processing' => count(glob($this->processingDir . '*.json')),
            'completed' => count(glob($this->completedDir . '*.json')),
            'failed' => count(glob($this->failedDir . '*.json')),
        ];
    }

    public function clearQueue(string $queue): bool
    {
        $dir = '';
        switch ($queue) {
            case 'completed':
                $dir = $this->completedDir;
                break;
            case 'failed':
                $dir = $this->failedDir;
                break;
            default:
                return false;
        }

        $files = glob($dir . '*.json');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->logger->info('Cleared queue', ['queue' => $queue]);
        return true;
    }
} 