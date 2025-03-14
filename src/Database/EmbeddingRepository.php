<?php
namespace EDUC\Database;

class EmbeddingRepository {
    private Database $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function storeEmbedding(
        string $documentId, 
        string $documentType, 
        string $content, 
        array $embedding, 
        array $metadata = []
    ): int {
        // Serialize the embedding to store as BLOB
        $embeddingBlob = serialize($embedding);
        
        // Convert metadata to JSON
        $metadataJson = json_encode($metadata);
        
        return $this->db->insert('embeddings', [
            'document_id' => $documentId,
            'document_type' => $documentType,
            'content' => $content,
            'embedding' => $embeddingBlob,
            'metadata' => $metadataJson
        ]);
    }
    
    public function storeChunk(string $documentId, int $chunkIndex, string $content): int {
        return $this->db->insert('chunks', [
            'document_id' => $documentId,
            'chunk_index' => $chunkIndex,
            'content' => $content
        ]);
    }
    
    public function getEmbedding(int $id): ?array {
        $sql = "SELECT * FROM embeddings WHERE id = :id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }
        
        // Unserialize the embedding
        $result['embedding'] = unserialize($result['embedding']);
        
        // Parse metadata
        if (!empty($result['metadata'])) {
            $result['metadata'] = json_decode($result['metadata'], true);
        } else {
            $result['metadata'] = [];
        }
        
        return $result;
    }
    
    public function getChunk(string $documentId, int $chunkIndex): ?array {
        $sql = "SELECT * FROM chunks WHERE document_id = :document_id AND chunk_index = :chunk_index";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':document_id', $documentId);
        $stmt->bindValue(':chunk_index', $chunkIndex, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    public function searchSimilarEmbeddings(array $queryEmbedding, int $limit = 5): array {
        // Since SQLite doesn't support vector operations natively,
        // we need to implement a simple dot product calculation in PHP
        
        // Get all embeddings
        $sql = "SELECT id, document_id, document_type, content, embedding, metadata FROM embeddings";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        
        $embeddings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $similarities = [];
        
        foreach ($embeddings as $embedding) {
            $embeddingVector = unserialize($embedding['embedding']);
            
            // Calculate cosine similarity
            $similarity = $this->cosineSimilarity($queryEmbedding, $embeddingVector);
            
            $similarities[] = [
                'id' => $embedding['id'],
                'document_id' => $embedding['document_id'],
                'document_type' => $embedding['document_type'],
                'content' => $embedding['content'],
                'metadata' => json_decode($embedding['metadata'], true),
                'similarity' => $similarity
            ];
        }
        
        // Sort by similarity (descending)
        usort($similarities, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Return top matches
        return array_slice($similarities, 0, $limit);
    }
    
    public function getAllDocumentIds(): array {
        $sql = "SELECT DISTINCT document_id FROM embeddings";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'document_id');
    }
    
    private function cosineSimilarity(array $a, array $b): float {
        $dotProduct = 0;
        $magnitudeA = 0;
        $magnitudeB = 0;
        
        foreach ($a as $i => $valueA) {
            $valueB = $b[$i] ?? 0;
            $dotProduct += $valueA * $valueB;
            $magnitudeA += $valueA * $valueA;
            $magnitudeB += $valueB * $valueB;
        }
        
        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);
        
        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0;
        }
        
        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
} 