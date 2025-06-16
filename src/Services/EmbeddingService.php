<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use NextcloudBot\Helpers\Logger;

class EmbeddingService
{
    private ApiClient $apiClient;
    private VectorStore $vectorStore;
    private Logger $logger;

    // A simple approximation for token count based on words.
    private const CHUNK_SIZE_IN_TOKENS = 250; 

    public function __construct(ApiClient $apiClient, VectorStore $vectorStore, Logger $logger)
    {
        $this->apiClient = $apiClient;
        $this->vectorStore = $vectorStore;
        $this->logger = $logger;
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
        $chunks = $this->chunkText($content);
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
     * Splits a text into smaller chunks based on a simple word count.
     *
     * @param string $text The text to split.
     * @return array An array of text chunks.
     */
    private function chunkText(string $text): array
    {
        // Normalize whitespace and split into words
        $text = preg_replace('/\s+/', ' ', trim($text));
        $words = explode(' ', $text);
        
        $chunks = [];
        $currentChunk = [];

        foreach ($words as $word) {
            $currentChunk[] = $word;
            if (count($currentChunk) >= self::CHUNK_SIZE_IN_TOKENS) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
            }
        }

        // Add the last remaining chunk
        if (!empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }
} 