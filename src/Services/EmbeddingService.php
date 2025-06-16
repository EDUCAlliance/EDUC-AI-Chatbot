<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use NextcloudBot\Helpers\Logger;

class EmbeddingService
{
    private ApiClient $apiClient;
    private VectorStore $vectorStore;
    private Logger $logger;
    private \PDO $db;

    // A simple approximation for token count based on words.
    private const CHUNK_SIZE_IN_TOKENS = 250; 

    public function __construct(ApiClient $apiClient, VectorStore $vectorStore, Logger $logger, \PDO $db)
    {
        $this->apiClient = $apiClient;
        $this->vectorStore = $vectorStore;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * Processes a document's content: chunks it, generates embeddings, and stores them.
     *
     * @param int $docId The ID of the document being processed.
     * @param string $content The full text content of the document.
     * @return bool True if all chunks were processed and stored successfully.
     */
    public function generateAndStoreEmbeddings(int $docId, string $content): bool
    {
        $settingsStmt = $this->db->query("SELECT rag_chunk_size, rag_chunk_overlap FROM bot_settings WHERE id = 1");
        $ragSettings = $settingsStmt->fetch();
        $chunkSize = $ragSettings['rag_chunk_size'] ?? 250;
        $chunkOverlap = $ragSettings['rag_chunk_overlap'] ?? 25;

        $chunks = $this->chunkText($content, $chunkSize, $chunkOverlap);
        $success = true;

        foreach ($chunks as $index => $chunk) {
            $embeddingResponse = $this->apiClient->getEmbedding($chunk);

            if (isset($embeddingResponse['error']) || empty($embeddingResponse['data'][0]['embedding'])) {
                $this->logger->error('Failed to get embedding for chunk', [
                    'doc_id' => $docId,
                    'chunk_index' => $index,
                    'error' => $embeddingResponse['error'] ?? 'Empty embedding data'
                ]);
                $success = false;
                continue; // Skip to the next chunk
            }

            $embedding = $embeddingResponse['data'][0]['embedding'];
            $stored = $this->vectorStore->storeEmbedding($docId, $index, $embedding, $chunk);

            if (!$stored) {
                $this->logger->error('Failed to store embedding for chunk', [
                    'doc_id' => $docId,
                    'chunk_index' => $index,
                ]);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Processes a document's content asynchronously with progress tracking.
     *
     * @param int $docId The ID of the document being processed.
     * @param string $content The full text content of the document.
     * @return bool True if all chunks were processed and stored successfully.
     */
    public function generateAndStoreEmbeddingsAsync(int $docId, string $content): bool
    {
        try {
            $settingsStmt = $this->db->query("SELECT rag_chunk_size, rag_chunk_overlap FROM bot_settings WHERE id = 1");
            $ragSettings = $settingsStmt->fetch();
            $chunkSize = $ragSettings['rag_chunk_size'] ?? 250;
            $chunkOverlap = $ragSettings['rag_chunk_overlap'] ?? 25;

            $chunks = $this->chunkText($content, $chunkSize, $chunkOverlap);
            $totalChunks = count($chunks);
            
            // Update progress with total chunks
            $this->updateProgress($docId, 0, 'Starting processing...', 0, $totalChunks);
            
            $this->logger->info('Starting async embedding generation', [
                'doc_id' => $docId,
                'total_chunks' => $totalChunks
            ]);

            $successCount = 0;
            
            foreach ($chunks as $index => $chunk) {
                // Update progress
                $progress = (int)(($index / $totalChunks) * 100);
                $this->updateProgress($docId, $progress, "Processing chunk " . ($index + 1) . " of $totalChunks...", $index + 1, $totalChunks);
                
                $embeddingResponse = $this->apiClient->getEmbedding($chunk);

                if (isset($embeddingResponse['error']) || empty($embeddingResponse['data'][0]['embedding'])) {
                    $this->logger->error('Failed to get embedding for chunk', [
                        'doc_id' => $docId,
                        'chunk_index' => $index,
                        'error' => $embeddingResponse['error'] ?? 'Empty embedding data'
                    ]);
                    continue; // Skip to the next chunk
                }

                $embedding = $embeddingResponse['data'][0]['embedding'];
                $stored = $this->vectorStore->storeEmbedding($docId, $index, $embedding, $chunk);

                if ($stored) {
                    $successCount++;
                } else {
                    $this->logger->error('Failed to store embedding for chunk', [
                        'doc_id' => $docId,
                        'chunk_index' => $index,
                    ]);
                }
                
                // Small delay to prevent overwhelming the API
                usleep(100000); // 0.1 seconds
            }
            
            // Mark as completed
            $this->updateProgress($docId, 100, 'Processing completed!', $totalChunks, $totalChunks, true);
            
            $this->logger->info('Async embedding generation completed', [
                'doc_id' => $docId,
                'total_chunks' => $totalChunks,
                'successful_chunks' => $successCount
            ]);

            return $successCount === $totalChunks;
            
        } catch (\Exception $e) {
            $this->logger->error('Async embedding generation failed', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
            
            // Update progress with error
            $this->updateProgress($docId, 0, 'Processing failed', 0, 0, false, $e->getMessage());
            
            return false;
        }
    }

    /**
     * Updates the progress tracking for a document.
     */
    private function updateProgress(int $docId, int $progress, string $status, int $currentChunk = 0, int $totalChunks = 0, bool $completed = false, string $errorMessage = null): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE bot_processing_progress 
                SET status = ?, progress = ?, current_chunk = ?, total_chunks = ?, error_message = ?, completed_at = ?
                WHERE doc_id = ?
            ");
            
            $completedAt = $completed ? date('Y-m-d H:i:s') : null;
            
            $stmt->execute([
                $status,
                $progress,
                $currentChunk,
                $totalChunks,
                $errorMessage,
                $completedAt,
                $docId
            ]);
        } catch (\PDOException $e) {
            $this->logger->error('Failed to update progress', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Splits a text into smaller chunks based on a simple word count.
     *
     * @param string $text The text to split.
     * @return array An array of text chunks.
     */
    private function chunkText(string $text, int $chunkSize, int $chunkOverlap): array
    {
        // Normalize whitespace and split into words
        $text = preg_replace('/\s+/', ' ', trim($text));
        $words = explode(' ', $text);
        
        $chunks = [];
        $currentChunk = [];
        $wordIndex = 0;

        while ($wordIndex < count($words)) {
            // Get a slice of words for the chunk
            $chunkSlice = array_slice($words, $wordIndex, $chunkSize);
            $chunks[] = implode(' ', $chunkSlice);

            // Move the index forward, considering the overlap
            $advance = $chunkSize - $chunkOverlap;
            $wordIndex += ($advance > 0) ? $advance : $chunkSize;
        }

        return $chunks;
    }
} 