<?php

namespace EDUC\Database;

use Exception;
use PDO;
use EDUC\Utils\Logger;

/**
 * Repository for managing embeddings in PostgreSQL with pgvector support
 */
class EmbeddingRepository {
    private Database $db;
    private string $tablePrefix;
    private bool $hasPgVector;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->tablePrefix = $db->getTablePrefix();
        $this->hasPgVector = $this->checkPgVectorSupport();
    }
    
    /**
     * Check if pgvector extension is available
     */
    private function checkPgVectorSupport(): bool {
        try {
            $result = $this->db->query("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'");
            return ($result[0]['count'] ?? 0) > 0;
        } catch (Exception $e) {
            Logger::warning('Could not check pgvector support', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Store embeddings for a document chunk
     */
    public function storeEmbedding(
        int $documentId,
        string $content,
        array $embedding,
        int $chunkIndex = 0,
        array $metadata = []
    ): int {
        try {
            if ($this->hasPgVector) {
                // Use native vector type
                $embeddingStr = '[' . implode(',', $embedding) . ']';
                $sql = "INSERT INTO {$this->tablePrefix}embeddings 
                        (document_id, content, chunk_index, embedding, metadata) 
                        VALUES (?, ?, ?, ?::vector, ?)";
                $params = [
                    $documentId,
                    $content,
                    $chunkIndex,
                    $embeddingStr,
                    json_encode($metadata)
                ];
            } else {
                // Fallback to storing as JSON text
                $sql = "INSERT INTO {$this->tablePrefix}embeddings 
                        (document_id, content, chunk_index, embedding_data, metadata) 
                        VALUES (?, ?, ?, ?, ?)";
                $params = [
                    $documentId,
                    $content,
                    $chunkIndex,
                    json_encode($embedding),
                    json_encode($metadata)
                ];
            }
            
            $this->db->execute($sql, $params);
            $embeddingId = (int)$this->db->lastInsertId();
            
            Logger::debug('Embedding stored', [
                'document_id' => $documentId,
                'embedding_id' => $embeddingId,
                'chunk_index' => $chunkIndex,
                'content_length' => strlen($content)
            ]);
            
            return $embeddingId;
            
        } catch (Exception $e) {
            Logger::error('Failed to store embedding', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
                'chunk_index' => $chunkIndex
            ]);
            throw $e;
        }
    }
    
    /**
     * Find similar content using embeddings
     */
    public function findSimilarContent(array $queryEmbedding, int $limit = 5): array {
        try {
            if ($this->hasPgVector) {
                return $this->findSimilarContentWithVector($queryEmbedding, $limit);
            } else {
                return $this->findSimilarContentWithCosine($queryEmbedding, $limit);
            }
        } catch (Exception $e) {
            Logger::error('Error finding similar content', [
                'error' => $e->getMessage(),
                'limit' => $limit
            ]);
            return [];
        }
    }
    
    /**
     * Find similar content using pgvector
     */
    private function findSimilarContentWithVector(array $queryEmbedding, int $limit): array {
        $embeddingStr = '[' . implode(',', $queryEmbedding) . ']';
        
        $sql = "SELECT e.content, e.metadata, e.embedding <-> ?::vector as distance,
                       d.original_filename, d.metadata as doc_metadata
                FROM {$this->tablePrefix}embeddings e
                JOIN {$this->tablePrefix}documents d ON e.document_id = d.id
                WHERE d.status = 'processed'
                ORDER BY e.embedding <-> ?::vector
                LIMIT ?";
        
        return $this->db->query($sql, [$embeddingStr, $embeddingStr, $limit]);
    }
    
    /**
     * Find similar content using cosine similarity calculation
     */
    private function findSimilarContentWithCosine(array $queryEmbedding, int $limit): array {
        // This is a fallback when pgvector is not available
        // Note: This approach is much slower and should only be used for small datasets
        
        $sql = "SELECT e.content, e.metadata, e.embedding_data,
                       d.original_filename, d.metadata as doc_metadata
                FROM {$this->tablePrefix}embeddings e
                JOIN {$this->tablePrefix}documents d ON e.document_id = d.id
                WHERE d.status = 'processed' AND e.embedding_data IS NOT NULL";
        
        $results = $this->db->query($sql);
        
        if (empty($results)) {
            return [];
        }
        
        // Calculate cosine similarity for each result
        $similarities = [];
        foreach ($results as $result) {
            $embedding = json_decode($result['embedding_data'], true);
            if ($embedding) {
                $similarity = $this->calculateCosineSimilarity($queryEmbedding, $embedding);
                $result['distance'] = 1 - $similarity; // Convert similarity to distance
                $similarities[] = $result;
            }
        }
        
        // Sort by similarity (lowest distance first)
        usort($similarities, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return array_slice($similarities, 0, $limit);
    }
    
    /**
     * Calculate cosine similarity between two vectors
     */
    private function calculateCosineSimilarity(array $a, array $b): float {
        if (count($a) !== count($b)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
    
    /**
     * Find similar content with additional filters
     */
    public function findSimilarContentWithFilters(
        array $queryEmbedding,
        int $limit,
        array $filters = []
    ): array {
        $whereConditions = ["d.status = 'processed'"];
        $params = [];
        
        // Add document filters
        if (!empty($filters['document_ids'])) {
            $placeholders = str_repeat('?,', count($filters['document_ids']) - 1) . '?';
            $whereConditions[] = "e.document_id IN ({$placeholders})";
            $params = array_merge($params, $filters['document_ids']);
        }
        
        if (!empty($filters['filename_pattern'])) {
            $whereConditions[] = "d.original_filename ILIKE ?";
            $params[] = '%' . $filters['filename_pattern'] . '%';
        }
        
        if ($this->hasPgVector) {
            $embeddingStr = '[' . implode(',', $queryEmbedding) . ']';
            $params = array_merge([$embeddingStr, $embeddingStr], $params, [$limit]);
            
            $sql = "SELECT e.content, e.metadata, e.embedding <-> ?::vector as distance,
                           d.original_filename, d.metadata as doc_metadata
                    FROM {$this->tablePrefix}embeddings e
                    JOIN {$this->tablePrefix}documents d ON e.document_id = d.id
                    WHERE " . implode(' AND ', $whereConditions) . "
                    ORDER BY e.embedding <-> ?::vector
                    LIMIT ?";
            
            return $this->db->query($sql, $params);
        } else {
            // Fallback for systems without pgvector
            $sql = "SELECT e.content, e.metadata, e.embedding_data,
                           d.original_filename, d.metadata as doc_metadata
                    FROM {$this->tablePrefix}embeddings e
                    JOIN {$this->tablePrefix}documents d ON e.document_id = d.id
                    WHERE " . implode(' AND ', $whereConditions) . " AND e.embedding_data IS NOT NULL";
            
            $results = $this->db->query($sql, $params);
            
            // Calculate similarities and return top results
            $similarities = [];
            foreach ($results as $result) {
                $embedding = json_decode($result['embedding_data'], true);
                if ($embedding) {
                    $similarity = $this->calculateCosineSimilarity($queryEmbedding, $embedding);
                    $result['distance'] = 1 - $similarity;
                    $similarities[] = $result;
                }
            }
            
            usort($similarities, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });
            
            return array_slice($similarities, 0, $limit);
        }
    }
    
    /**
     * Find similar content within a specific document
     */
    public function findSimilarContentInDocument(
        array $queryEmbedding,
        int $documentId,
        int $limit
    ): array {
        return $this->findSimilarContentWithFilters(
            $queryEmbedding,
            $limit,
            ['document_ids' => [$documentId]]
        );
    }
    
    /**
     * Get embeddings for a specific document
     */
    public function getDocumentEmbeddings(int $documentId): array {
        $sql = "SELECT * FROM {$this->tablePrefix}embeddings 
                WHERE document_id = ? 
                ORDER BY chunk_index";
        
        return $this->db->query($sql, [$documentId]);
    }
    
    /**
     * Delete embeddings for a document
     */
    public function deleteDocumentEmbeddings(int $documentId): int {
        $sql = "DELETE FROM {$this->tablePrefix}embeddings WHERE document_id = ?";
        $connection = $this->db->getConnection();
        $stmt = $connection->prepare($sql);
        $stmt->execute([$documentId]);
        
        // Return the number of affected rows
        return $stmt->rowCount();
    }
    
    /**
     * Get total number of embeddings
     */
    public function getTotalEmbeddingsCount(): int {
        $result = $this->db->query("SELECT COUNT(*) as count FROM {$this->tablePrefix}embeddings");
        return $result[0]['count'] ?? 0;
    }
    
    /**
     * Get total number of documents with embeddings
     */
    public function getTotalDocumentsCount(): int {
        $sql = "SELECT COUNT(DISTINCT document_id) as count FROM {$this->tablePrefix}embeddings";
        $result = $this->db->query($sql);
        return $result[0]['count'] ?? 0;
    }
    
    /**
     * Get average number of embeddings per document
     */
    public function getAverageEmbeddingsPerDocument(): float {
        $sql = "SELECT COALESCE(AVG(embedding_count), 0) as avg_count FROM (
                    SELECT COUNT(*) as embedding_count 
                    FROM {$this->tablePrefix}embeddings 
                    GROUP BY document_id
                ) as counts";
        
        $result = $this->db->query($sql);
        return (float)($result[0]['avg_count'] ?? 0);
    }
    
    /**
     * Get embedding statistics
     */
    public function getEmbeddingStats(): array {
        return [
            'total_embeddings' => $this->getTotalEmbeddingsCount(),
            'total_documents' => $this->getTotalDocumentsCount(),
            'avg_embeddings_per_document' => $this->getAverageEmbeddingsPerDocument(),
            'has_pgvector' => $this->hasPgVector,
            'embedding_dimension' => $this->getEmbeddingDimension()
        ];
    }
    
    /**
     * Get the dimension of stored embeddings
     */
    public function getEmbeddingDimension(): ?int {
        try {
            if ($this->hasPgVector) {
                $sql = "SELECT array_length(embedding, 1) as dimension 
                        FROM {$this->tablePrefix}embeddings 
                        WHERE embedding IS NOT NULL 
                        LIMIT 1";
            } else {
                $sql = "SELECT embedding_data 
                        FROM {$this->tablePrefix}embeddings 
                        WHERE embedding_data IS NOT NULL 
                        LIMIT 1";
            }
            
            $result = $this->db->query($sql);
            
            if (empty($result)) {
                return null;
            }
            
            if ($this->hasPgVector) {
                return (int)($result[0]['dimension'] ?? 0);
            } else {
                $embedding = json_decode($result[0]['embedding_data'], true);
                return $embedding ? count($embedding) : null;
            }
        } catch (Exception $e) {
            Logger::warning('Could not determine embedding dimension', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Check if pgvector is supported
     */
    public function hasPgVector(): bool {
        return $this->hasPgVector;
    }
    
    /**
     * Clean up orphaned embeddings (embeddings without corresponding documents)
     */
    public function cleanupOrphanedEmbeddings(): int {
        $sql = "DELETE FROM {$this->tablePrefix}embeddings 
                WHERE document_id NOT IN (
                    SELECT id FROM {$this->tablePrefix}documents
                )";
        
        $connection = $this->db->getConnection();
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        
        $deletedCount = $stmt->rowCount();
        
        if ($deletedCount > 0) {
            Logger::info('Cleaned up orphaned embeddings', ['deleted_count' => $deletedCount]);
        }
        
        return $deletedCount;
    }
}
?> 