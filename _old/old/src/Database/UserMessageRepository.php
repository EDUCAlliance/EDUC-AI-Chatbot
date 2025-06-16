<?php
namespace EDUC\Database;

class UserMessageRepository {
    private Database $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function logMessage(string $userId, string $targetId, string $role, string $message): int {
        $insertedId = $this->db->insert('user_messages', [
            'user_id' => $userId,
            'target_id' => $targetId,
            'role' => $role,
            'message' => $message
        ]);

        // Prune older messages, keeping only the last 30 per user/target combination
        $limit = 30; // Keep the last 30 messages
        $sql = "DELETE FROM user_messages
                WHERE user_id = :user_id AND target_id = :target_id AND id NOT IN (
                    SELECT id FROM user_messages
                    WHERE user_id = :user_id AND target_id = :target_id
                    ORDER BY id DESC
                    LIMIT :limit
                )";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':target_id', $targetId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $insertedId;
    }
    
    public function getUserMessageHistory(string $userId, string $targetId, int $limit = 30): array {
        $sql = "SELECT role, message, timestamp FROM user_messages 
                WHERE user_id = :user_id AND target_id = :target_id 
                ORDER BY id DESC 
                LIMIT :limit";
                
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':target_id', $targetId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $formattedHistory = [];
        foreach (array_reverse($messages) as $msg) {
            $formattedHistory[] = [
                'role' => $msg['role'],
                'content' => $msg['message'],
                'timestamp' => $msg['timestamp']
            ];
        }
        return $formattedHistory;
    }
    
    public function deleteUserMessages(string $userId, ?string $targetId = null): int {
        if ($targetId === null) {
            // Original behavior: delete all messages for a user across all targets
            // This might need reconsideration based on new requirements, but keeping for now if no targetId is specified.
            $sql = "DELETE FROM user_messages WHERE user_id = :user_id";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->bindValue(':user_id', $userId);
        } else {
            // New behavior: delete messages for a specific user in a specific target
            $sql = "DELETE FROM user_messages WHERE user_id = :user_id AND target_id = :target_id";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->bindValue(':user_id', $userId);
            $stmt->bindValue(':target_id', $targetId);
        }
        $stmt->execute();
        
        return $stmt->rowCount();
    }

    public function deleteMessagesByTarget(string $targetId): int {
        $sql = "DELETE FROM user_messages WHERE target_id = :target_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':target_id', $targetId);
        $stmt->execute();
        return $stmt->rowCount();
    }
} 