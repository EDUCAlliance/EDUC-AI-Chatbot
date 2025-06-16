<?php

declare(strict_types=1);

namespace EducBot\Models;

use PDO;
use Exception;
use EducBot\Helpers\Logger;

/**
 * Conversation Model
 * 
 * Manages conversation history, message storage, and conversation analytics
 */
class Conversation
{
    private static ?PDO $db = null;

    public static function init(): void
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }
    }

    /**
     * Create a new conversation message
     */
    public static function create(array $data): int
    {
        self::init();

        $requiredFields = ['room_token', 'user_id', 'role', 'content'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Required field '{$field}' is missing");
            }
        }

        if (!in_array($data['role'], ['user', 'assistant', 'system'])) {
            throw new Exception("Invalid role: {$data['role']}");
        }

        try {
            $stmt = self::$db->prepare('
                INSERT INTO bot_conversations 
                (room_token, user_id, user_name, role, content, model_used, tokens_used, processing_time_ms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['room_token'],
                $data['user_id'],
                $data['user_name'] ?? null,
                $data['role'],
                $data['content'],
                $data['model_used'] ?? null,
                $data['tokens_used'] ?? null,
                $data['processing_time_ms'] ?? null
            ]);

            $id = (int)self::$db->lastInsertId();

            Logger::debug('Conversation message created', [
                'id' => $id,
                'room_token' => $data['room_token'],
                'role' => $data['role'],
                'content_length' => strlen($data['content'])
            ]);

            return $id;

        } catch (Exception $e) {
            Logger::error('Failed to create conversation message', [
                'room_token' => $data['room_token'],
                'role' => $data['role'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get conversation history for a room
     */
    public static function getHistory(string $roomToken, int $limit = 20, int $offset = 0): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT * FROM bot_conversations 
                WHERE room_token = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ');

            $stmt->execute([$roomToken, $limit, $offset]);
            $messages = $stmt->fetchAll();

            // Reverse to get chronological order (oldest first)
            return array_reverse($messages);

        } catch (Exception $e) {
            Logger::error('Failed to get conversation history', [
                'room_token' => $roomToken,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get recent messages across all conversations
     */
    public static function getRecentMessages(int $limit = 50): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT * FROM bot_conversations 
                ORDER BY created_at DESC 
                LIMIT ?
            ');

            $stmt->execute([$limit]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get recent messages', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get conversation statistics for a room
     */
    public static function getRoomStats(string $roomToken): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT 
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN role = \'user\' THEN 1 END) as user_messages,
                    COUNT(CASE WHEN role = \'assistant\' THEN 1 END) as bot_responses,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_activity,
                    MIN(created_at) as first_activity,
                    AVG(processing_time_ms) as avg_processing_time,
                    SUM(tokens_used) as total_tokens
                FROM bot_conversations 
                WHERE room_token = ?
            ');

            $stmt->execute([$roomToken]);
            $stats = $stmt->fetch();

            return $stats ?: [];

        } catch (Exception $e) {
            Logger::error('Failed to get room stats', [
                'room_token' => $roomToken,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get global conversation statistics
     */
    public static function getGlobalStats(): array
    {
        self::init();

        try {
            $stmt = self::$db->query('
                SELECT 
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN role = \'user\' THEN 1 END) as user_messages,
                    COUNT(CASE WHEN role = \'assistant\' THEN 1 END) as bot_responses,
                    COUNT(DISTINCT room_token) as active_rooms,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_activity,
                    MIN(created_at) as first_activity,
                    AVG(processing_time_ms) as avg_processing_time,
                    SUM(tokens_used) as total_tokens
                FROM bot_conversations
            ');

            $stats = $stmt->fetch();
            return $stats ?: [];

        } catch (Exception $e) {
            Logger::error('Failed to get global stats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get conversation statistics for date range
     */
    public static function getStatsForPeriod(string $startDate, string $endDate): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN role = \'user\' THEN 1 END) as user_messages,
                    COUNT(CASE WHEN role = \'assistant\' THEN 1 END) as bot_responses,
                    COUNT(DISTINCT room_token) as active_rooms,
                    COUNT(DISTINCT user_id) as unique_users,
                    AVG(processing_time_ms) as avg_processing_time,
                    SUM(tokens_used) as total_tokens
                FROM bot_conversations 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ');

            $stmt->execute([$startDate, $endDate]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get period stats', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get most active users
     */
    public static function getMostActiveUsers(int $limit = 10): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT 
                    user_id,
                    user_name,
                    COUNT(*) as message_count,
                    MAX(created_at) as last_activity
                FROM bot_conversations 
                WHERE role = \'user\'
                GROUP BY user_id, user_name
                ORDER BY message_count DESC
                LIMIT ?
            ');

            $stmt->execute([$limit]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get most active users', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get most active rooms
     */
    public static function getMostActiveRooms(int $limit = 10): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT 
                    room_token,
                    COUNT(*) as message_count,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_activity
                FROM bot_conversations 
                GROUP BY room_token
                ORDER BY message_count DESC
                LIMIT ?
            ');

            $stmt->execute([$limit]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to get most active rooms', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search messages by content
     */
    public static function searchMessages(string $query, ?string $roomToken = null, int $limit = 50): array
    {
        self::init();

        try {
            $sql = '
                SELECT * FROM bot_conversations 
                WHERE content ILIKE ?
            ';
            $params = ["%{$query}%"];

            if ($roomToken !== null) {
                $sql .= ' AND room_token = ?';
                $params[] = $roomToken;
            }

            $sql .= ' ORDER BY created_at DESC LIMIT ?';
            $params[] = $limit;

            $stmt = self::$db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to search messages', [
                'query' => $query,
                'room_token' => $roomToken,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Delete old conversations to save space
     */
    public static function deleteOldConversations(int $daysToKeep = 90): int
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                DELETE FROM bot_conversations 
                WHERE created_at < NOW() - INTERVAL ? DAY
            ');

            $stmt->execute([$daysToKeep]);
            $deletedCount = $stmt->rowCount();

            Logger::info('Old conversations cleaned up', [
                'days_to_keep' => $daysToKeep,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Logger::error('Failed to delete old conversations', [
                'days_to_keep' => $daysToKeep,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete all conversations for a room
     */
    public static function deleteRoomConversations(string $roomToken): int
    {
        self::init();

        try {
            $stmt = self::$db->prepare('DELETE FROM bot_conversations WHERE room_token = ?');
            $stmt->execute([$roomToken]);
            $deletedCount = $stmt->rowCount();

            Logger::info('Room conversations deleted', [
                'room_token' => $roomToken,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Logger::error('Failed to delete room conversations', [
                'room_token' => $roomToken,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get conversation context for RAG
     * Returns formatted conversation history suitable for AI context
     */
    public static function getContextForRAG(string $roomToken, int $maxMessages = 10): string
    {
        $history = self::getHistory($roomToken, $maxMessages);
        
        if (empty($history)) {
            return '';
        }

        $context = "Recent conversation history:\n\n";
        
        foreach ($history as $message) {
            $role = ucfirst($message['role']);
            $content = trim($message['content']);
            $timestamp = date('H:i', strtotime($message['created_at']));
            
            if ($message['role'] === 'user') {
                $userName = $message['user_name'] ?: 'User';
                $context .= "[{$timestamp}] {$userName}: {$content}\n";
            } elseif ($message['role'] === 'assistant') {
                $context .= "[{$timestamp}] Assistant: {$content}\n";
            }
        }

        return trim($context);
    }

    /**
     * Export conversations for backup
     */
    public static function exportConversations(?string $roomToken = null, ?string $startDate = null, ?string $endDate = null): array
    {
        self::init();

        try {
            $sql = 'SELECT * FROM bot_conversations WHERE 1=1';
            $params = [];

            if ($roomToken !== null) {
                $sql .= ' AND room_token = ?';
                $params[] = $roomToken;
            }

            if ($startDate !== null) {
                $sql .= ' AND DATE(created_at) >= ?';
                $params[] = $startDate;
            }

            if ($endDate !== null) {
                $sql .= ' AND DATE(created_at) <= ?';
                $params[] = $endDate;
            }

            $sql .= ' ORDER BY created_at ASC';

            $stmt = self::$db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            Logger::error('Failed to export conversations', [
                'room_token' => $roomToken,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get user activity summary
     */
    public static function getUserActivity(string $userId, int $days = 30): array
    {
        self::init();

        try {
            $stmt = self::$db->prepare('
                SELECT 
                    COUNT(*) as total_messages,
                    COUNT(DISTINCT room_token) as rooms_participated,
                    AVG(LENGTH(content)) as avg_message_length,
                    MAX(created_at) as last_activity,
                    MIN(created_at) as first_activity
                FROM bot_conversations 
                WHERE user_id = ? 
                AND role = \'user\'
                AND created_at >= NOW() - INTERVAL ? DAY
            ');

            $stmt->execute([$userId, $days]);
            $stats = $stmt->fetch();

            return $stats ?: [];

        } catch (Exception $e) {
            Logger::error('Failed to get user activity', [
                'user_id' => $userId,
                'days' => $days,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
} 