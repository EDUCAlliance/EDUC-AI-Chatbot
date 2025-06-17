<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use PDO;
use Pgvector\Vector;

class VectorStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Stores a vector embedding for a document chunk in the database.
     *
     * @param int $docId The ID of the document.
     * @param int $chunkIndex The index of the chunk within the document.
     * @param array $embedding The vector embedding.
     * @param string $text The original text of the chunk.
     * @return bool True on success, false on failure.
     */
    public function storeEmbedding(int $docId, int $chunkIndex, array $embedding, string $text): bool
    {
        $sql = "INSERT INTO bot_embeddings (doc_id, chunk_index, embedding, text) 
                VALUES (:doc_id, :chunk_index, :embedding, :text)
                ON CONFLICT (doc_id, chunk_index) DO UPDATE SET
                embedding = EXCLUDED.embedding,
                text = EXCLUDED.text";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':doc_id' => $docId,
            ':chunk_index' => $chunkIndex,
            ':embedding' => new Vector($embedding),
            ':text' => $text
        ]);
    }

    /**
     * Finds the most similar document chunks for a given query vector.
     *
     * @param array $queryEmbedding The vector embedding of the user's query.
     * @param int $limit The maximum number of similar chunks to return.
     * @param int $botId The ID of the bot whose documents should be searched.
     * @return array An array of the most similar text chunks.
     */
    public function findSimilar(array $queryEmbedding, int $limit = 5, ?int $botId = null): array
    {
        if ($botId === null) {
            // Or handle this case as an error, depending on requirements
            return []; 
        }

        // The <-> operator performs cosine similarity search
        $sql = "SELECT text
                FROM bot_embeddings
                JOIN bot_docs ON bot_embeddings.doc_id = bot_docs.id
                WHERE bot_docs.bot_id = :bot_id
                ORDER BY embedding <-> :query
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':query', new Vector($queryEmbedding));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
} 