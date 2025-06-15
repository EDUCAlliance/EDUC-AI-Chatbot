<?php

namespace EDUC\Database;

use PDO;
use PDOException;
use Exception;
use EDUC\Core\Environment;
use EDUC\Utils\Logger;

/**
 * Database management class with PostgreSQL and pgvector support
 * Compatible with Cloudron deployment environment
 */
class Database {
    private static ?Database $instance = null;
    private PDO $connection;
    private string $tablePrefix = 'educat_'; // App-specific prefix
    
    private function __construct() {
        $this->connect();
        $this->initializeSchema();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private function connect(): void {
        try {
            // Try Cloudron database URL first
            $databaseUrl = Environment::get('DATABASE_URL') ?? Environment::get('CLOUDRON_POSTGRESQL_URL');
            
            if ($databaseUrl) {
                $this->connection = new PDO($databaseUrl);
                Logger::info('Connected to database using Cloudron URL');
            } else {
                // Fallback to individual parameters
                $host = Environment::get('DB_HOST', 'localhost');
                $port = Environment::get('DB_PORT', '5432');
                $database = Environment::get('DB_NAME', 'chatbot');
                $username = Environment::get('DB_USER', 'postgres');
                $password = Environment::get('DB_PASSWORD', '');
                
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                $this->connection = new PDO($dsn, $username, $password);
                Logger::info('Connected to database using individual parameters');
            }
            
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable pgvector extension if available
            $this->enablePgVector();
            
        } catch (PDOException $e) {
            Logger::error('Database connection failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Enable pgvector extension for embeddings
     */
    private function enablePgVector(): void {
        try {
            $this->connection->exec("CREATE EXTENSION IF NOT EXISTS vector");
            Logger::info('pgvector extension enabled');
        } catch (PDOException $e) {
            Logger::warning('Could not enable pgvector extension', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Initialize database schema
     */
    private function initializeSchema(): void {
        $this->createSettingsTable();
        $this->createMessagesTable();
        $this->createChatConfigTable();
        $this->createDocumentsTable();
        $this->createEmbeddingsTable();
        $this->createFilesTable();
        $this->createModelsTable();
        
        // Insert default settings if not exists
        $this->insertDefaultSettings();
        
        Logger::info('Database schema initialized');
    }
    
    /**
     * Create settings table
     */
    private function createSettingsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}settings (
            id SERIAL PRIMARY KEY,
            key VARCHAR(255) UNIQUE NOT NULL,
            value TEXT,
            description TEXT,
            is_sensitive BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
    }
    
    /**
     * Create messages table
     */
    private function createMessagesTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}messages (
            id SERIAL PRIMARY KEY,
            user_id VARCHAR(255) NOT NULL,
            target_id VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content TEXT NOT NULL,
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
        
        // Create index for messages
        $indexSql = "CREATE INDEX IF NOT EXISTS {$this->tablePrefix}messages_user_target_idx 
                    ON {$this->tablePrefix}messages (user_id, target_id, created_at)";
        $this->connection->exec($indexSql);
    }
    
    /**
     * Create chat configuration table
     */
    private function createChatConfigTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}chat_configs (
            id SERIAL PRIMARY KEY,
            target_id VARCHAR(255) UNIQUE NOT NULL,
            is_group_chat BOOLEAN DEFAULT FALSE,
            requires_mention BOOLEAN DEFAULT TRUE,
            onboarding_step INTEGER DEFAULT 0,
            current_question_index INTEGER DEFAULT 0,
            onboarding_answers JSONB DEFAULT '{}',
            settings JSONB DEFAULT '{}',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
    }
    
    /**
     * Create documents table
     */
    private function createDocumentsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}documents (
            id SERIAL PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT NOT NULL,
            mime_type VARCHAR(100),
            status VARCHAR(50) DEFAULT 'pending',
            processed_at TIMESTAMP NULL,
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
    }
    
    /**
     * Create embeddings table with pgvector support
     */
    private function createEmbeddingsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}embeddings (
            id SERIAL PRIMARY KEY,
            document_id INTEGER REFERENCES {$this->tablePrefix}documents(id) ON DELETE CASCADE,
            content TEXT NOT NULL,
            chunk_index INTEGER DEFAULT 0,
            embedding vector(1536),
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        try {
            $this->connection->exec($sql);
            
            // Create index for similarity search
            $indexSql = "CREATE INDEX IF NOT EXISTS {$this->tablePrefix}embeddings_embedding_idx 
                        ON {$this->tablePrefix}embeddings USING ivfflat (embedding vector_cosine_ops)
                        WITH (lists = 100)";
            $this->connection->exec($indexSql);
            
        } catch (PDOException $e) {
            // If pgvector is not available, create table without vector column
            Logger::warning('Creating embeddings table without pgvector support');
            $fallbackSql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}embeddings (
                id SERIAL PRIMARY KEY,
                document_id INTEGER REFERENCES {$this->tablePrefix}documents(id) ON DELETE CASCADE,
                content TEXT NOT NULL,
                chunk_index INTEGER DEFAULT 0,
                embedding_data TEXT,
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $this->connection->exec($fallbackSql);
        }
    }
    
    /**
     * Create files table for upload tracking
     */
    private function createFilesTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}files (
            id SERIAL PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT NOT NULL,
            mime_type VARCHAR(100),
            upload_type VARCHAR(50) DEFAULT 'document',
            status VARCHAR(50) DEFAULT 'uploaded',
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
    }
    
    /**
     * Create models cache table
     */
    private function createModelsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tablePrefix}models (
            id SERIAL PRIMARY KEY,
            model_id VARCHAR(255) UNIQUE NOT NULL,
            model_name VARCHAR(255) NOT NULL,
            capabilities JSONB DEFAULT '[]',
            provider VARCHAR(100) DEFAULT 'gwdg',
            is_active BOOLEAN DEFAULT TRUE,
            metadata JSONB DEFAULT '{}',
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->connection->exec($sql);
    }
    
    /**
     * Insert default settings
     */
    private function insertDefaultSettings(): void {
        $defaultSettings = [
            'system_prompt' => [
                'value' => 'You are a helpful AI assistant for the EDUC project. Provide accurate, helpful, and educational responses.',
                'description' => 'System prompt for the AI model'
            ],
            'model' => [
                'value' => 'meta-llama-3.1-8b-instruct',
                'description' => 'Default AI model to use'
            ],
            'bot_mention' => [
                'value' => 'AI Assistant',
                'description' => 'Bot mention name for Nextcloud Talk'
            ],
            'debug_mode' => [
                'value' => 'false',
                'description' => 'Enable debug mode for detailed logging'
            ],
            'welcome_message' => [
                'value' => 'Hello! I\'m your AI assistant. How can I help you today?',
                'description' => 'Welcome message for new conversations'
            ],
            'user_onboarding_questions' => [
                'value' => json_encode([
                    'What is your primary field of study or work?',
                    'What type of assistance are you looking for?',
                    'Are there any specific topics you\'d like me to focus on?'
                ]),
                'description' => 'Onboarding questions for individual users'
            ],
            'group_onboarding_questions' => [
                'value' => json_encode([
                    'What is the main purpose of this group?',
                    'What type of discussions will take place here?',
                    'Should I help with specific topics or general assistance?'
                ]),
                'description' => 'Onboarding questions for group chats'
            ]
        ];
        
        foreach ($defaultSettings as $key => $setting) {
            $this->insertSettingIfNotExists($key, $setting['value'], $setting['description']);
        }
    }
    
    /**
     * Insert setting if it doesn't exist
     */
    private function insertSettingIfNotExists(string $key, string $value, string $description = ''): void {
        $stmt = $this->connection->prepare(
            "INSERT INTO {$this->tablePrefix}settings (key, value, description) 
             VALUES (?, ?, ?) 
             ON CONFLICT (key) DO NOTHING"
        );
        $stmt->execute([$key, $value, $description]);
    }
    
    /**
     * Get database connection
     */
    public function getConnection(): PDO {
        return $this->connection;
    }
    
    /**
     * Get table prefix
     */
    public function getTablePrefix(): string {
        return $this->tablePrefix;
    }
    
    /**
     * Get a setting value
     */
    public function getSetting(string $key, ?string $default = null): ?string {
        $stmt = $this->connection->prepare(
            "SELECT value FROM {$this->tablePrefix}settings WHERE key = ?"
        );
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        return $result !== false ? $result : $default;
    }
    
    /**
     * Set a setting value
     */
    public function setSetting(string $key, string $value, string $description = ''): void {
        $stmt = $this->connection->prepare(
            "INSERT INTO {$this->tablePrefix}settings (key, value, description, updated_at) 
             VALUES (?, ?, ?, CURRENT_TIMESTAMP) 
             ON CONFLICT (key) DO UPDATE SET 
             value = EXCLUDED.value, 
             description = EXCLUDED.description,
             updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$key, $value, $description]);
    }
    
    /**
     * Get all settings
     */
    public function getAllSettings(): array {
        $stmt = $this->connection->query(
            "SELECT key, value, description, is_sensitive FROM {$this->tablePrefix}settings ORDER BY key"
        );
        
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key']] = [
                'value' => $row['value'],
                'description' => $row['description'],
                'is_sensitive' => $row['is_sensitive']
            ];
        }
        
        return $settings;
    }
    
    /**
     * Get chat configuration
     */
    public function getChatConfig(string $targetId): ?array {
        $stmt = $this->connection->prepare(
            "SELECT * FROM {$this->tablePrefix}chat_configs WHERE target_id = ?"
        );
        $stmt->execute([$targetId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Save chat configuration
     */
    public function saveChatConfig(string $targetId, array $config): void {
        $stmt = $this->connection->prepare(
            "INSERT INTO {$this->tablePrefix}chat_configs 
             (target_id, is_group_chat, requires_mention, onboarding_step, current_question_index, onboarding_answers, settings, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) 
             ON CONFLICT (target_id) DO UPDATE SET 
             is_group_chat = EXCLUDED.is_group_chat,
             requires_mention = EXCLUDED.requires_mention,
             onboarding_step = EXCLUDED.onboarding_step,
             current_question_index = EXCLUDED.current_question_index,
             onboarding_answers = EXCLUDED.onboarding_answers,
             settings = EXCLUDED.settings,
             updated_at = CURRENT_TIMESTAMP"
        );
        
        $stmt->execute([
            $targetId,
            $config['is_group_chat'] ?? false,
            $config['requires_mention'] ?? true,
            $config['onboarding_step'] ?? 0,
            $config['current_question_index'] ?? 0,
            json_encode($config['onboarding_answers'] ?? []),
            json_encode($config['settings'] ?? [])
        ]);
    }
    
    /**
     * Delete chat configuration
     */
    public function deleteChatConfig(string $targetId): void {
        $stmt = $this->connection->prepare(
            "DELETE FROM {$this->tablePrefix}chat_configs WHERE target_id = ?"
        );
        $stmt->execute([$targetId]);
    }
    
    /**
     * Execute a prepared statement
     */
    public function execute(string $sql, array $params = []): bool {
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Fetch results from a query
     */
    public function query(string $sql, array $params = []): array {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): string {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): bool {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(): bool {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollBack(): bool {
        return $this->connection->rollBack();
    }
}
?> 