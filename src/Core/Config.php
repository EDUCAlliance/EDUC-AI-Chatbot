<?php
namespace EDUC\Core;

// Require the ConfigRepository if not using an autoloader
// require_once __DIR__ . '/ConfigRepository.php';

use PDO;

class Config {
    private array $config;
    private static ?Config $instance = null;
    private ConfigRepository $configRepo;

    // Modified constructor to accept ConfigRepository
    private function __construct(ConfigRepository $configRepo) {
        $this->configRepo = $configRepo;
        $this->loadConfigFromDb();
    }

    // Method to load or reload config from the database
    private function loadConfigFromDb(): void {
        $this->config = $this->configRepo->getAllConfig();
        // Ensure essential keys have default values if not found in DB
        // This prevents errors if the DB is empty or keys are missing
        $this->config['systemPrompt'] = $this->config['systemPrompt'] ?? 'Default system prompt: You are a helpful assistant.';
        $this->config['model'] = $this->config['model'] ?? 'default-model';
        $this->config['botMention'] = $this->config['botMention'] ?? 'BotName';
        // Note: We no longer load responseExamples from here.
    }

    // Modified getInstance to accept ConfigRepository
    public static function initialize(ConfigRepository $configRepo): self {
        if (self::$instance === null) {
            self::$instance = new self($configRepo);
        } else {
            // Optionally update the repo and reload if already initialized
            self::$instance->configRepo = $configRepo;
            self::$instance->loadConfigFromDb();
        }
        return self::$instance;
    }

    // Get the initialized instance
    public static function getInstance(): self {
        if (self::$instance === null) {
            // This path indicates initialize() was not called, which is an error in the new flow.
            throw new \Exception("Config instance has not been initialized. Call Config::initialize() first.");
        }
        return self::$instance;
    }

    public function get(string $key, $default = null) {
        // Return from the loaded config array
        return $this->config[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->config;
    }

    // Setting config directly on this object is discouraged; use ConfigRepository
    /*
    public function set(string $key, $value): void {
        // This would only update the in-memory config, not the DB
        // $this->config[$key] = $value;
        // To persist, you would need:
        // $this->configRepo->setConfig($key, $value);
        // Consider removing this method or making it clear it's temporary
    }
    */
} 