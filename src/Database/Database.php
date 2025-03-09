<?php
namespace EDUC\Database;

class Database {
    private \PDO $connection;
    private static ?Database $instance = null;

    private function __construct(string $dbPath) {
        $this->connection = new \PDO("sqlite:" . $dbPath);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initialize();
    }

    public static function getInstance(string $dbPath): self {
        if (self::$instance === null) {
            self::$instance = new self($dbPath);
        }
        return self::$instance;
    }

    private function initialize(): void {
        // Create user_messages table if it doesn't exist
        $this->connection->exec("CREATE TABLE IF NOT EXISTS user_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT,
            message TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Create embeddings table if it doesn't exist
        $this->connection->exec("CREATE TABLE IF NOT EXISTS embeddings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id TEXT,
            document_type TEXT,
            content TEXT,
            embedding BLOB,
            metadata TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Create chunks table for storing document chunks
        $this->connection->exec("CREATE TABLE IF NOT EXISTS chunks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id TEXT,
            chunk_index INTEGER,
            content TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function getConnection(): \PDO {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): \PDOStatement {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($data);
        
        return (int)$this->connection->lastInsertId();
    }

    public function select(string $table, array $columns = ['*'], array $where = [], string $orderBy = '', string $limit = ''): array {
        $columnsStr = implode(', ', $columns);
        $sql = "SELECT $columnsStr FROM $table";
        
        $params = [];
        if (!empty($where)) {
            $whereClauses = [];
            foreach ($where as $key => $value) {
                $whereClauses[] = "$key = :$key";
                $params[":$key"] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }
        
        if (!empty($orderBy)) {
            $sql .= " ORDER BY $orderBy";
        }
        
        if (!empty($limit)) {
            $sql .= " LIMIT $limit";
        }
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
} 