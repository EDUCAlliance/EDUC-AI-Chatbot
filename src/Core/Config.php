<?php
namespace EDUC\Core;

class Config {
    private array $config;
    private static ?Config $instance = null;

    private function __construct(string $configPath) {
        $configContent = file_get_contents($configPath);
        if ($configContent === false) {
            throw new \Exception("Error loading config file: $configPath");
        }

        $config = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error parsing config file: ' . json_last_error_msg());
        }

        $this->config = $config;
    }

    public static function getInstance(string $configPath = null): self {
        if (self::$instance === null && $configPath !== null) {
            self::$instance = new self($configPath);
        }
        
        if (self::$instance === null) {
            throw new \Exception("Config instance not initialized");
        }
        
        return self::$instance;
    }

    public function get(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->config;
    }

    public function set(string $key, $value): void {
        $this->config[$key] = $value;
    }
} 