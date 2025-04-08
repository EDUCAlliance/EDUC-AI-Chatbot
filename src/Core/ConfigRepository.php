<?php

namespace Educ\Talkbot\Core;

use PDO;
use PDOException;

class ConfigRepository
{
    private PDO $db;

    public function __construct(Database $database)
    {
        $this->db = $database->getConnection();
        $this->initializeTable();
    }

    private function initializeTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS configuration (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key TEXT UNIQUE NOT NULL,
                    value TEXT NOT NULL
                )"
            );
        } catch (PDOException $e) {
            // Consider logging this error instead of echoing
            error_log("Database Error: Failed to create configuration table - " . $e->getMessage());
            throw new \RuntimeException("Failed to initialize configuration table.", 0, $e);
        }
    }

    public function getConfig(string $key): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT value FROM configuration WHERE key = :key");
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['value'] : null;
        } catch (PDOException $e) {
            error_log("Database Error: Failed to get config key '$key' - " . $e->getMessage());
            return null;
        }
    }

    public function setConfig(string $key, string $value): bool
    {
        try {
            // Use INSERT OR REPLACE (SQLite specific) or separate INSERT/UPDATE logic
            $stmt = $this->db->prepare("
                INSERT INTO configuration (key, value)
                VALUES (:key, :value)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value;
            ");
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->bindParam(':value', $value, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Database Error: Failed to set config key '$key' - " . $e->getMessage());
            return false;
        }
    }

    public function getAllConfig(): array
    {
        $config = [];
        try {
            $stmt = $this->db->query("SELECT key, value FROM configuration");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['key']] = $row['value'];
            }
        } catch (PDOException $e) {
            error_log("Database Error: Failed to get all config - " . $e->getMessage());
            // Depending on requirements, you might want to throw an exception here
        }
        return $config;
    }

     /**
     * Loads initial configuration from a JSON file if the configuration table is empty.
     *
     * @param string $jsonFilePath Path to the llm_config.json file.
     * @return bool True if initial config was loaded, false otherwise or if table wasn't empty.
     */
    public function loadInitialConfigFromJson(string $jsonFilePath): bool
    {
        try {
            // Check if table is empty
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM configuration");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['count'] > 0) {
                return false; // Table is not empty, don't load initial config
            }

            if (!file_exists($jsonFilePath)) {
                error_log("Initial config file not found: {$jsonFilePath}");
                return false;
            }

            $jsonContent = file_get_contents($jsonFilePath);
            if ($jsonContent === false) {
                error_log("Failed to read initial config file: {$jsonFilePath}");
                return false;
            }

            $configData = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Failed to decode initial config JSON: " . json_last_error_msg());
                return false;
            }

            $success = true;
            if (isset($configData['systemPrompt'])) {
                $success &= $this->setConfig('systemPrompt', $configData['systemPrompt']);
            }
            if (isset($configData['model'])) {
                $success &= $this->setConfig('model', $configData['model']);
            }
            if (isset($configData['botMention'])) {
                $success &= $this->setConfig('botMention', $configData['botMention']);
            }
            // Note: responseExamples are intentionally ignored as per requirements.

            return $success;
        } catch (PDOException $e) {
            error_log("Database Error during initial config load: " . $e->getMessage());
            return false;
        }
    }
}

?> 