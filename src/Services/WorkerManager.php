<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use NextcloudBot\Helpers\Logger;
use NextcloudBot\Helpers\TalkHelper;
use \PDO;
use \Throwable;

class WorkerManager
{
    private Logger $logger;
    private ApiClient $apiClient;
    private PDO $db;

    public function __construct(Logger $logger, ApiClient $apiClient, PDO $db)
    {
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->db = $db;
    }

    public function enqueueJob(array $jobData): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO bot_job_queue (job_data) VALUES (?)"
            );
            return $stmt->execute([json_encode($jobData)]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to enqueue job to database', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function processQueue(int $limit = 10): array
    {
        $this->logger->info('Starting DB queue processing batch.');
        $processed = 0;
        $failed = 0;
        $total = 0;

        $this->db->beginTransaction();
        try {
            // Atomically select and lock pending jobs
            $stmt = $this->db->prepare(
                "SELECT id FROM bot_job_queue 
                 WHERE status = 'pending' 
                 ORDER BY created_at 
                 LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $jobIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $total = count($jobIds);

            if ($total > 0) {
                // Mark jobs as 'processing'
                $idsPlaceholder = implode(',', array_fill(0, $total, '?'));
                $updateStmt = $this->db->prepare(
                    "UPDATE bot_job_queue SET status = 'processing', updated_at = NOW(), attempts = attempts + 1 WHERE id IN ($idsPlaceholder)"
                );
                $updateStmt->execute($jobIds);
            }
            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to select jobs from queue', ['error' => $e->getMessage()]);
            return ['processed' => 0, 'failed' => 0, 'total' => 0];
        }

        if ($total === 0) {
            $this->logger->info('No pending jobs found in DB.');
            return ['processed' => 0, 'failed' => 0, 'total' => 0];
        }

        $this->logger->info("Processing $total jobs from DB queue.");

        foreach ($jobIds as $jobId) {
            try {
                // Fetch the full job data
                $jobStmt = $this->db->prepare("SELECT * FROM bot_job_queue WHERE id = ?");
                $jobStmt->execute([$jobId]);
                $job = $jobStmt->fetch();
                $jobData = json_decode($job['job_data'], true);

                $llmResponse = $this->apiClient->getChatCompletions($jobData['model'], $jobData['messages']);
                
                if (isset($llmResponse['error'])) {
                     throw new \Exception('LLM API returned an error: ' . ($llmResponse['error']['message'] ?? json_encode($llmResponse['error'])));
                }

                $replyContent = $llmResponse['choices'][0]['message']['content'] ?? 'Sorry, I encountered an error and cannot reply right now.';
                $this->logger->info('LLM response received successfully', ['jobId' => $jobId]);

                $convStmt = $this->db->prepare("INSERT INTO bot_conversations (room_token, user_id, role, content, bot_id) VALUES (?, ?, 'assistant', ?, ?)");
                $convStmt->execute([$jobData['roomToken'], 'assistant', $replyContent, $jobData['botId']]);

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
                
                $this->updateJobStatus($jobId, 'completed');
                $processed++;

            } catch (Throwable $e) {
                $this->logger->error('Job failed during execution', ['jobId' => $jobId, 'error' => $e->getMessage()]);
                $this->updateJobStatus($jobId, 'failed', $e->getMessage());
                $failed++;
            }
        }
        
        $this->logger->info('DB queue processing batch finished.', ['processed' => $processed, 'failed' => $failed]);
        return ['processed' => $processed, 'failed' => $failed, 'total' => $total];
    }

    private function updateJobStatus(int $jobId, string $status, ?string $errorMessage = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE bot_job_queue SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$status, $errorMessage, $jobId]);
    }
    
    public function getQueueStats(): array
    {
        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $stmt = $this->db->query("SELECT status, COUNT(*) as count FROM bot_job_queue GROUP BY status");
        $results = $stmt->fetchAll();
        foreach ($results as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int)$row['count'];
            }
        }
        return $stats;
    }

    public function clearQueue(string $queue): bool
    {
        if (!in_array($queue, ['completed', 'failed'])) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("DELETE FROM bot_job_queue WHERE status = ?");
            $stmt->execute([$queue]);
            $this->logger->info('Cleared queue from DB', ['queue' => $queue]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to clear DB queue', ['queue' => $queue, 'error' => $e->getMessage()]);
            return false;
        }
    }
} 