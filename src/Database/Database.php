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
        // Create NEW user_messages table with role and assistant_response
        $this->connection->exec("CREATE TABLE IF NOT EXISTS user_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            target_id TEXT NOT NULL, -- Added target_id
            role TEXT NOT NULL,  -- 'user' or 'assistant'
            message TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Add index for faster queries on user_id and target_id
        $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_user_target ON user_messages (user_id, target_id)");
        $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_user_messages_target ON user_messages (target_id)");

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

        // Create chat_configs table
        $this->connection->exec("CREATE TABLE IF NOT EXISTS chat_configs (
            target_id TEXT PRIMARY KEY,
            is_group_chat BOOLEAN DEFAULT FALSE,
            requires_mention BOOLEAN DEFAULT TRUE,
            onboarding_step INTEGER DEFAULT 0, -- 0:new, 1:asked_mention, 2:asked_type, 3:asking_questions, 4:complete
            current_question_index INTEGER DEFAULT 0,
            onboarding_answers TEXT, -- JSON
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
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

        // Ensure 'debug' setting exists, default to 'false'
        $stmt = $this->connection->prepare("SELECT 1 FROM settings WHERE key = 'debug'");
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            $this->saveSetting('debug', 'false');
            error_log("Initialized default setting 'debug' to 'false'");
        }
        
        // Ensure onboarding questions settings exist, default to empty JSON array '[]'
        $defaultOnboardingQuestions = '[]';
        if ($this->getSetting('user_onboarding_questions') === null) {
            $this->saveSetting('user_onboarding_questions', $defaultOnboardingQuestions);
            error_log("Initialized default setting 'user_onboarding_questions'");
        }
        if ($this->getSetting('group_onboarding_questions') === null) {
            $this->saveSetting('group_onboarding_questions', $defaultOnboardingQuestions);
            error_log("Initialized default setting 'group_onboarding_questions'");
        }
        
        // Remove legacy welcomeMessage if it exists
        $stmt = $this->connection->prepare("DELETE FROM settings WHERE key = 'welcomeMessage'");
        $stmt->execute();

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
        $stmt = $this->connection->prepare("SELECT value FROM settings WHERE key = :key");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    // Method to get all settings
    public function getAllSettings(): array {
        $stmt = $this->connection->query("SELECT key, value FROM settings");
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR); // Returns an associative array [key => value]
    }

    // Method to save/update a specific setting
    public function saveSetting(string $key, string $value): void {
        // Using REPLACE INTO for simplicity (requires key to be PRIMARY KEY or UNIQUE)
        $stmt = $this->connection->prepare("REPLACE INTO settings (key, value) VALUES (:key, :value)");
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    // Methods for chat_configs table
    public function getChatConfig(string $targetId): ?array {
        $stmt = $this->connection->prepare("SELECT * FROM chat_configs WHERE target_id = :target_id");
        $stmt->execute([':target_id' => $targetId]);
        $config = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $config ?: null;
    }

    public function saveChatConfig(string $targetId, array $configData): void {
        $configData['target_id'] = $targetId; // Ensure target_id is in the data
        $configData['last_updated'] = date('Y-m-d H:i:s');

        $columns = [];
        $placeholders = [];
        $updateSet = [];
        foreach ($configData as $key => $value) {
            $columns[] = $key;
            $placeholders[] = ":$key";
            if ($key !== 'target_id') { // Don't include primary key in update set
                $updateSet[] = "$key = :$key";
            }
        }

        $sql = "INSERT INTO chat_configs (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")
                ON CONFLICT(target_id) DO UPDATE SET " . implode(', ', $updateSet);
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($configData);
    }

    public function deleteChatConfig(string $targetId): void {
        $stmt = $this->connection->prepare("DELETE FROM chat_configs WHERE target_id = :target_id");
        $stmt->execute([':target_id' => $targetId]);
    }
} 