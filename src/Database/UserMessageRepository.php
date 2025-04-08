<?php
namespace EDUC\Database;

class UserMessageRepository {
    private Database $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function logMessage(string $userId, string $role, string $message): int {
        return $this->db->insert('user_messages', [
            'user_id' => $userId,
            'role' => $role,
            'message' => $message
        ]);
    }
    
    public function getUserMessageHistory(string $userId, int $limit = 20): array {
        $sql = "SELECT role, message FROM user_messages 
                WHERE user_id = :user_id 
                ORDER BY id DESC 
                LIMIT :limit";
                
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $formattedHistory = [];
        foreach (array_reverse($messages) as $msg) {
            $formattedHistory[] = [
                'role' => $msg['role'],
                'content' => $msg['message']
            ];
        }
        return $formattedHistory;
    }
    
    public function deleteUserMessages(string $userId): int {
        $sql = "DELETE FROM user_messages WHERE user_id = :user_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
} 