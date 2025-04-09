<?php
namespace EDUC\Database;

use EDUC\Core\Environment; // Need Environment to load initial config path

class Database {
    private \PDO $connection;
    private static ?Database $instance = null;
    private bool $settingsInitialized = false; // Flag to track settings initialization

    private function __construct(string $dbPath) {
        $this->connection = new \PDO("sqlite:" . $dbPath);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initialize();
        // Initialize settings after tables are ensured
        $this->initializeSettings();
    }

    public static function getInstance(string $dbPath): self {
        if (self::$instance === null) {
            self::$instance = new self($dbPath);
        }
        return self::$instance;
    }

    private function initialize(): void {
        // Drop old user_messages table if it exists (to change structure)
        // Warning: This deletes existing chat history!
        // Consider a more sophisticated migration strategy for production.
        $this->connection->exec("DROP TABLE IF EXISTS user_messages");

        // Create NEW user_messages table with role and assistant_response
        $this->connection->exec("CREATE TABLE IF NOT EXISTS user_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            role TEXT NOT NULL,  -- 'user' or 'assistant'
            message TEXT NOT NULL,
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

        // Create settings table if it doesn't exist
        $this->connection->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
    }

    // New method to initialize settings from llm_config.json if settings table is empty
    private function initializeSettings(): void {
        if ($this->settingsInitialized) return; // Only run once per instance

        $stmt = $this->connection->query("SELECT COUNT(*) FROM settings");
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            // Settings table is empty, load from llm_config.json
            // Prefer environment variable, otherwise construct path assuming public root
            $configPath = Environment::get('AI_CONFIG_FILE');
            if (!$configPath) {
                // Construct path: Go up two levels from src/Database to the public root
                $configPath = '/app/code/public/llm_config.json'; 
            } else {
                 // Ensure the path from ENV is absolute or resolve relative to a known root if needed
                 // Assuming ENV variable provides a usable path directly for now
                 $configPath = '/app/code/public/llm_config.json'; 
            }
            
            // Normalize the path to handle potential .. or . and assign to $resolvedPath
            $resolvedPath = realpath($configPath);

            if ($resolvedPath && file_exists($resolvedPath)) { // Check if realpath resolved and file exists
                $configContent = file_get_contents($resolvedPath);
                $config = json_decode($configContent, true);

                if ($config && json_last_error() === JSON_ERROR_NONE) {
                    $systemPrompt = $config['systemPrompt'] ?? '';
                    $model = $config['model'] ?? '';
                    $botMention = $config['botMention'] ?? '';

                    if (!empty($systemPrompt) && !empty($model) && !empty($botMention)) {
                        $this->saveSetting('systemPrompt', $systemPrompt);
                        $this->saveSetting('model', $model);
                        $this->saveSetting('botMention', $botMention);
                        error_log("Initialized settings from $configPath");
                    } else {
                        error_log("Could not initialize settings: required keys missing in $configPath");
                    }
                } else {
                    error_log("Could not initialize settings: Error parsing $resolvedPath: " . json_last_error_msg());
                }
            } else {
                // Log the path we tried to check
                error_log("Could not initialize settings: Config file not found or not readable at path: '" . $configPath . "' (Resolved to: '" . ($resolvedPath ?: '[failed to resolve]') . "')");
            }
        }
        $this->settingsInitialized = true;
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

    // Method to get a specific setting
    public function getSetting(string $key, $default = null): ?string {
        $stmt = $this->query("SELECT value FROM settings WHERE key = :key", [':key' => $key]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? $result['value'] : $default;
    }

    // Method to save/update a specific setting
    public function saveSetting(string $key, string $value): void {
        // Using REPLACE INTO for simplicity (requires key to be PRIMARY KEY or UNIQUE)
        $stmt = $this->connection->prepare("REPLACE INTO settings (key, value) VALUES (:key, :value)");
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    // Method to get all settings
    public function getAllSettings(): array {
        $stmt = $this->query("SELECT key, value FROM settings");
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR); // Returns an associative array [key => value]
    }
} 