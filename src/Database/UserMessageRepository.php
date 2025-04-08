<?php
namespace EDUC\Database;

use PDO;
use PDOException;

class UserMessageRepository {
    private Database $db;
    private PDO $pdo;
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->pdo = $db->getConnection();
        $this->initializeTable(); // Ensure table exists on instantiation
    }
    
    /**
     * Creates or updates the chat_history table schema.
     */
    private function initializeTable(): void {
        try {
            // Check if the old table exists and rename it (optional backup)
            // $this->pdo->exec("ALTER TABLE user_messages RENAME TO old_user_messages;");
            // Create the new table if it doesn't exist
            $this->pdo->exec("\n                CREATE TABLE IF NOT EXISTS chat_history (\n                    id INTEGER PRIMARY KEY AUTOINCREMENT,\n                    user_id TEXT NOT NULL,\n                    role TEXT NOT NULL CHECK(role IN ('user', 'assistant', 'system')),\n                    content TEXT NOT NULL,\n                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP\n                )"
            );
            // Optional: Add index for faster history retrieval
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_history_user_timestamp ON chat_history (user_id, timestamp);");
        } catch (PDOException $e) {
            error_log("Database Error: Failed to initialize chat_history table - " . $e->getMessage());
            // Rethrow or handle as appropriate for your application
            throw new \RuntimeException("Failed to initialize chat_history table.", 0, $e);
        }
    }
    
    /**
     * Logs a single message (user or assistant) to the history.
     *
     * @param string $userId The identifier for the user/conversation.
     * @param string $role The role ('user' or 'assistant').
     * @param string $content The message content.
     * @return int The ID of the inserted row, or 0 on failure.
     */
    public function logChatMessage(string $userId, string $role, string $content): int {
        // Basic validation
        if (empty($userId) || !in_array($role, ['user', 'assistant']) || empty($content)) {
            error_log("Invalid arguments provided to logChatMessage for user {$userId}");
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_history (user_id, role, content) VALUES (:user_id, :role, :content)'
            );
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
            $stmt->bindParam(':role', $role, PDO::PARAM_STR);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            $stmt->execute();
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Database Error: Failed to log chat message for user {$userId} - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Retrieves the chat history for a user, formatted for the LLM API.
     *
     * @param string $userId The user identifier.
     * @param int $limit Maximum number of messages to retrieve.
     * @return array Array of messages [{'role': ..., 'content': ...}].
     */
    public function getChatHistory(string $userId, int $limit = 20): array {
        // Retrieve slightly more to potentially filter system messages if needed later
        $effectiveLimit = $limit;
        
        $sql = "SELECT role, content FROM chat_history\n                WHERE user_id = :user_id\n                ORDER BY timestamp DESC\n                LIMIT :limit";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $effectiveLimit, PDO::PARAM_INT);
            $stmt->execute();
            
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Reverse to get chronological order (oldest first)
            return array_reverse($messages);
            
        } catch (PDOException $e) {
            error_log("Database Error: Failed to get chat history for user {$userId} - " . $e->getMessage());
            return []; // Return empty array on error
        }
    }
    
    /**
     * Deletes all chat history for a specific user.
     *
     * @param string $userId The user identifier.
     * @return int Number of rows affected.
     */
    public function deleteUserChatHistory(string $userId): int {
        try {
            $sql = "DELETE FROM chat_history WHERE user_id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Database Error: Failed to delete chat history for user {$userId} - " . $e->getMessage());
            return 0;
        }
    }
    
    // Keep old methods temporarily for compatibility or remove them
    /*
    public function logMessage(string $userId, string $message): int {
        // Redirect to new method or deprecate
        return $this->logChatMessage($userId, 'user', $message);
    }
    public function getUserMessageHistory(string $userId, int $limit = 50): array {
       // This format is different, might need adjustment or deprecation
       $history = $this->getChatHistory($userId, $limit);
       // Convert back to old format if needed, otherwise deprecate
       return array_map(function($msg) {
           return ['message' => $msg['content'], 'timestamp' => 'N/A']; // Timestamp lost
       }, $history);
    }
    public function deleteUserMessages(string $userId): int {
        return $this->deleteUserChatHistory($userId);
    }
    */
} 