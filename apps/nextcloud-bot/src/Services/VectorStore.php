<?php

declare(strict_types=1);

namespace EducBot\Services;

use PDO;
use Exception;
use EducBot\Helpers\Logger;

/**
 * Vector Store Service
 * 
 * Manages document embeddings and similarity search using PostgreSQL with pgvector extension
 */
class VectorStore
{
    private static ?PDO $db = null;

    public function __construct()
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }
    }

    /**
     * Store document embedding chunks
     */
    public function storeEmbeddings(int $docId, array $chunks): bool
    {
        try {
            self::$db->beginTransaction();

            // Delete existing embeddings for this document
            $this->deleteDocumentEmbeddings($docId);

            $stmt = self::$db->prepare('
                INSERT INTO bot_embeddings (doc_id, chunk_index, embedding, text_content, chunk_metadata)
                VALUES (?, ?, ?, ?, ?)
            ');

            foreach ($chunks as $index => $chunk) {
                if (empty($chunk['embedding']) || empty($chunk['text'])) {
                    throw new Exception("Invalid chunk data at index {$index}");
                }

                // Convert embedding array to PostgreSQL vector format
                $embeddingVector = '[' . implode(',', $chunk['embedding']) . ']';

                $stmt->execute([
                    $docId,
                    $index,
                    $embeddingVector,
                    $chunk['text'],
                    json_encode($chunk['metadata'] ?? [])
                ]);
            }

            self::$db->commit();

            Logger::info('Document embeddings stored successfully', [
                'doc_id' => $docId,
                'chunks_count' => count($chunks)
            ]);

            return true;

        } catch (Exception $e) {
            self::$db->rollBack();
            Logger::error('Failed to store embeddings', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Search for similar content using vector similarity
     */
    public function searchSimilar(array $queryEmbedding, int $limit = 5, float $threshold = 0.3): array
    {
        try {
            // Convert embedding array to PostgreSQL vector format
            $embeddingVector = '[' . implode(',', $queryEmbedding) . ']';

            $stmt = self::$db->prepare('
                SELECT 
                    be.doc_id,
                    be.chunk_index,
                    be.text_content,
                    be.chunk_metadata,
                    bd.filename,
                    bd.original_filename,
                    1 - (be.embedding <=> ?::vector) as similarity
                FROM bot_embeddings be
                JOIN bot_docs bd ON be.doc_id = bd.id
                WHERE 1 - (be.embedding <=> ?::vector) > ?
                AND bd.status = \'completed\'
                ORDER BY be.embedding <=> ?::vector
                LIMIT ?
            ');

            $stmt->execute([
                $embeddingVector,
                $embeddingVector,
                $threshold,
                $embeddingVector,
                $limit
            ]);

            $results = $stmt->fetchAll();

            Logger::debug('Vector similarity search completed', [
                'query_embedding_dimensions' => count($queryEmbedding),
                'results_count' => count($results),
                'threshold' => $threshold
            ]);

            return $results;

        } catch (Exception $e) {
            Logger::error('Vector similarity search failed', [
                'error' => $e->getMessage(),
                'embedding_dimensions' => count($queryEmbedding)
            ]);
            throw $e;
        }
    }

    /**
     * Delete embeddings for a specific document
     */
    public function deleteDocumentEmbeddings(int $docId): bool
    {
        try {
            $stmt = self::$db->prepare('DELETE FROM bot_embeddings WHERE doc_id = ?');
            $result = $stmt->execute([$docId]);

            if ($result) {
                Logger::debug('Document embeddings deleted', ['doc_id' => $docId]);
            }

            return $result;

        } catch (Exception $e) {
            Logger::error('Failed to delete document embeddings', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get embedding statistics
     */
    public function getStats(): array
    {
        try {
            $stmt = self::$db->query('
                SELECT 
                    COUNT(*) as total_embeddings,
                    COUNT(DISTINCT doc_id) as documents_with_embeddings,
                    AVG(LENGTH(text_content)) as avg_chunk_length,
                    MIN(LENGTH(text_content)) as min_chunk_length,
                    MAX(LENGTH(text_content)) as max_chunk_length
                FROM bot_embeddings
            ');

            $stats = $stmt->fetch();
            return $stats ?: [];

        } catch (Exception $e) {
            Logger::error('Failed to get embedding stats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get embeddings for a specific document
     */
    public function getDocumentEmbeddings(int $docId): array
    {
        try {
            $stmt = self::$db->prepare('
                SELECT 
                    chunk_index,
                    text_content,
                    chunk_metadata,
                    embedding
                FROM bot_embeddings 
                WHERE doc_id = ?
                ORDER BY chunk_index
            ');

            $stmt->execute([$docId]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get document embeddings', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Search embeddings by text content
     */
    public function searchByText(string $query, int $limit = 10): array
    {
        try {
            $stmt = self::$db->prepare('
                SELECT 
                    be.doc_id,
                    be.chunk_index,
                    be.text_content,
                    be.chunk_metadata,
                    bd.filename,
                    bd.original_filename
                FROM bot_embeddings be
                JOIN bot_docs bd ON be.doc_id = bd.id
                WHERE be.text_content ILIKE ?
                AND bd.status = \'completed\'
                ORDER BY be.doc_id, be.chunk_index
                LIMIT ?
            ');

            $stmt->execute(["%{$query}%", $limit]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Text search in embeddings failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get embeddings by similarity threshold for a document
     */
    public function getDocumentSimilarChunks(int $docId, array $queryEmbedding, float $threshold = 0.5): array
    {
        try {
            $embeddingVector = '[' . implode(',', $queryEmbedding) . ']';

            $stmt = self::$db->prepare('
                SELECT 
                    chunk_index,
                    text_content,
                    chunk_metadata,
                    1 - (embedding <=> ?::vector) as similarity
                FROM bot_embeddings 
                WHERE doc_id = ?
                AND 1 - (embedding <=> ?::vector) > ?
                ORDER BY embedding <=> ?::vector
            ');

            $stmt->execute([
                $embeddingVector,
                $docId,
                $embeddingVector,
                $threshold,
                $embeddingVector
            ]);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get similar chunks for document', [
                'doc_id' => $docId,
                'threshold' => $threshold,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Update embedding index for better performance
     */
    public function updateIndex(): bool
    {
        try {
            // Refresh the vector index
            self::$db->exec('REINDEX INDEX idx_embeddings_vector');

            // Update table statistics
            self::$db->exec('ANALYZE bot_embeddings');

            Logger::info('Vector index updated successfully');
            return true;

        } catch (Exception $e) {
            Logger::error('Failed to update vector index', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get nearest neighbors for debugging
     */
    public function getNearestNeighbors(array $queryEmbedding, int $k = 10): array
    {
        try {
            $embeddingVector = '[' . implode(',', $queryEmbedding) . ']';

            $stmt = self::$db->prepare('
                SELECT 
                    be.doc_id,
                    be.chunk_index,
                    be.text_content,
                    bd.filename,
                    be.embedding <=> ?::vector as distance,
                    1 - (be.embedding <=> ?::vector) as similarity
                FROM bot_embeddings be
                JOIN bot_docs bd ON be.doc_id = bd.id
                WHERE bd.status = \'completed\'
                ORDER BY be.embedding <=> ?::vector
                LIMIT ?
            ');

            $stmt->execute([
                $embeddingVector,
                $embeddingVector,
                $embeddingVector,
                $k
            ]);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get nearest neighbors', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clean up orphaned embeddings
     */
    public function cleanupOrphanedEmbeddings(): int
    {
        try {
            $stmt = self::$db->query('
                DELETE FROM bot_embeddings 
                WHERE doc_id NOT IN (SELECT id FROM bot_docs)
            ');

            $deletedCount = $stmt->rowCount();

            Logger::info('Orphaned embeddings cleaned up', [
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Logger::error('Failed to cleanup orphaned embeddings', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Export embeddings for backup
     */
    public function exportEmbeddings(?int $docId = null): array
    {
        try {
            $sql = '
                SELECT 
                    be.*,
                    bd.filename,
                    bd.original_filename
                FROM bot_embeddings be
                JOIN bot_docs bd ON be.doc_id = bd.id
            ';
            
            $params = [];
            if ($docId !== null) {
                $sql .= ' WHERE be.doc_id = ?';
                $params[] = $docId;
            }
            
            $sql .= ' ORDER BY be.doc_id, be.chunk_index';

            $stmt = self::$db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to export embeddings', [
                'doc_id' => $docId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get vector dimensions for validation
     */
    public function getVectorDimensions(): ?int
    {
        try {
            $stmt = self::$db->query('
                SELECT array_length(embedding, 1) as dimensions
                FROM bot_embeddings 
                WHERE embedding IS NOT NULL
                LIMIT 1
            ');

            $result = $stmt->fetch();
            return $result ? (int)$result['dimensions'] : null;

        } catch (Exception $e) {
            Logger::error('Failed to get vector dimensions', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate embedding consistency
     */
    public function validateEmbeddings(): array
    {
        try {
            $issues = [];

            // Check for embeddings with inconsistent dimensions
            $stmt = self::$db->query('
                SELECT 
                    doc_id,
                    chunk_index,
                    array_length(embedding, 1) as dimensions
                FROM bot_embeddings 
                WHERE array_length(embedding, 1) != 1024
            ');

            $inconsistentDims = $stmt->fetchAll();
            if (!empty($inconsistentDims)) {
                $issues['inconsistent_dimensions'] = $inconsistentDims;
            }

            // Check for NULL embeddings
            $stmt = self::$db->query('
                SELECT COUNT(*) as null_count
                FROM bot_embeddings 
                WHERE embedding IS NULL
            ');

            $nullCount = $stmt->fetchColumn();
            if ($nullCount > 0) {
                $issues['null_embeddings'] = $nullCount;
            }

            // Check for empty text content
            $stmt = self::$db->query('
                SELECT COUNT(*) as empty_text_count
                FROM bot_embeddings 
                WHERE text_content IS NULL OR text_content = \'\'
            ');

            $emptyTextCount = $stmt->fetchColumn();
            if ($emptyTextCount > 0) {
                $issues['empty_text'] = $emptyTextCount;
            }

            return $issues;

        } catch (Exception $e) {
            Logger::error('Failed to validate embeddings', ['error' => $e->getMessage()]);
            return ['validation_error' => $e->getMessage()];
        }
    }
} 