<?php

declare(strict_types=1);

namespace EducBot\Models;

use PDO;
use Exception;
use EducBot\Helpers\Logger;

/**
 * Settings Model
 * 
 * Manages bot configuration and system settings stored in the database
 */
class Settings
{
    private static ?PDO $db = null;
    private static ?array $cache = null;

    public function __construct()
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }
    }

    /**
     * Get all settings as an array
     */
    public function getAll(): array
    {
        if (self::$cache === null) {
            $this->loadSettings();
        }
        return self::$cache;
    }

    /**
     * Get a specific setting value
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }

    /**
     * Update a setting value
     */
    public function set(string $key, $value): bool
    {
        try {
            $stmt = self::$db->prepare("UPDATE bot_settings SET {$key} = ? WHERE id = 1");
            $result = $stmt->execute([$value]);
            
            if ($result) {
                // Clear cache to force reload
                self::$cache = null;
                Logger::info('Setting updated', ['key' => $key]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error('Failed to update setting', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update multiple settings at once
     */
    public function updateMultiple(array $settings): bool
    {
        try {
            self::$db->beginTransaction();
            
            foreach ($settings as $key => $value) {
                $stmt = self::$db->prepare("UPDATE bot_settings SET {$key} = ? WHERE id = 1");
                $stmt->execute([$value]);
            }
            
            self::$db->commit();
            
            // Clear cache
            self::$cache = null;
            
            Logger::info('Multiple settings updated', ['keys' => array_keys($settings)]);
            return true;
            
        } catch (Exception $e) {
            self::$db->rollBack();
            Logger::error('Failed to update multiple settings', [
                'keys' => array_keys($settings),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Load settings from database
     */
    private function loadSettings(): void
    {
        try {
            $stmt = self::$db->query('SELECT * FROM bot_settings WHERE id = 1');
            $settings = $stmt->fetch();
            
            if ($settings === false) {
                // No settings found, create default settings
                $this->createDefaultSettings();
                $stmt = self::$db->query('SELECT * FROM bot_settings WHERE id = 1');
                $settings = $stmt->fetch();
            }
            
            self::$cache = $settings;
            
        } catch (Exception $e) {
            Logger::error('Failed to load settings from database', ['error' => $e->getMessage()]);
            // Use default values if database fails
            self::$cache = $this->getDefaultSettings();
        }
    }

    /**
     * Create default settings in database
     */
    private function createDefaultSettings(): void
    {
        $defaults = $this->getDefaultSettings();
        
        $stmt = self::$db->prepare('
            INSERT INTO bot_settings (
                id, mention, default_model, embedding_model, system_prompt, 
                max_tokens, temperature, top_k, onboarding_group, onboarding_dm
            ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO NOTHING
        ');
        
        $stmt->execute([
            $defaults['mention'],
            $defaults['default_model'],
            $defaults['embedding_model'],
            $defaults['system_prompt'],
            $defaults['max_tokens'],
            $defaults['temperature'],
            $defaults['top_k'],
            json_encode($defaults['onboarding_group']),
            json_encode($defaults['onboarding_dm'])
        ]);
        
        Logger::info('Default settings created in database');
    }

    /**
     * Get default settings values
     */
    private function getDefaultSettings(): array
    {
        return [
            'mention' => '@educai',
            'default_model' => 'meta-llama-3.1-8b-instruct',
            'embedding_model' => 'e5-mistral-7b-instruct',
            'system_prompt' => 'You are EDUC AI, a helpful AI assistant for the EDUC Alliance. You have access to educational resources and can help with questions about online learning, educational technology, and academic collaboration.',
            'max_tokens' => 512,
            'temperature' => 0.7,
            'top_k' => 5,
            'onboarding_group' => [
                'Is this a group chat (yes/no)?',
                'Should I respond to every message or only when mentioned?',
                'What type of educational content would you like help with?'
            ],
            'onboarding_dm' => [
                'What subject area are you most interested in?',
                'Are you a student, teacher, or researcher?',
                'What type of assistance do you need most often?'
            ]
        ];
    }

    // Convenience methods for commonly used settings

    public function getBotMention(): string
    {
        return $this->get('mention', '@educai');
    }

    public function getDefaultModel(): string
    {
        return $this->get('default_model', 'meta-llama-3.1-8b-instruct');
    }

    public function getEmbeddingModel(): string
    {
        return $this->get('embedding_model', 'e5-mistral-7b-instruct');
    }

    public function getSystemPrompt(): string
    {
        return $this->get('system_prompt', 'You are EDUC AI, a helpful AI assistant.');
    }

    public function getMaxTokens(): int
    {
        return (int)$this->get('max_tokens', 512);
    }

    public function getTemperature(): float
    {
        return (float)$this->get('temperature', 0.7);
    }

    public function getTopK(): int
    {
        return (int)$this->get('top_k', 5);
    }

    public function getOnboardingQuestions(bool $isGroup = true): array
    {
        $key = $isGroup ? 'onboarding_group' : 'onboarding_dm';
        $questions = $this->get($key, []);
        
        // If stored as JSON string, decode it
        if (is_string($questions)) {
            $questions = json_decode($questions, true) ?? [];
        }
        
        return $questions;
    }

    public function setOnboardingQuestions(array $questions, bool $isGroup = true): bool
    {
        $key = $isGroup ? 'onboarding_group' : 'onboarding_dm';
        return $this->set($key, json_encode($questions));
    }

    /**
     * Reset all settings to defaults
     */
    public function resetToDefaults(): bool
    {
        try {
            $defaults = $this->getDefaultSettings();
            
            $stmt = self::$db->prepare('
                UPDATE bot_settings SET
                    mention = ?,
                    default_model = ?,
                    embedding_model = ?,
                    system_prompt = ?,
                    max_tokens = ?,
                    temperature = ?,
                    top_k = ?,
                    onboarding_group = ?,
                    onboarding_dm = ?
                WHERE id = 1
            ');
            
            $result = $stmt->execute([
                $defaults['mention'],
                $defaults['default_model'],
                $defaults['embedding_model'],
                $defaults['system_prompt'],
                $defaults['max_tokens'],
                $defaults['temperature'],
                $defaults['top_k'],
                json_encode($defaults['onboarding_group']),
                json_encode($defaults['onboarding_dm'])
            ]);
            
            if ($result) {
                self::$cache = null; // Clear cache
                Logger::info('Settings reset to defaults');
            }
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error('Failed to reset settings to defaults', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Validate settings values
     */
    public function validate(array $settings): array
    {
        $errors = [];
        
        // Validate mention format
        if (isset($settings['mention'])) {
            if (!preg_match('/^@[a-zA-Z0-9_-]+$/', $settings['mention'])) {
                $errors['mention'] = 'Mention must start with @ and contain only letters, numbers, _ or -';
            }
        }
        
        // Validate max_tokens
        if (isset($settings['max_tokens'])) {
            $maxTokens = (int)$settings['max_tokens'];
            if ($maxTokens < 1 || $maxTokens > 2048) {
                $errors['max_tokens'] = 'Max tokens must be between 1 and 2048';
            }
        }
        
        // Validate temperature
        if (isset($settings['temperature'])) {
            $temperature = (float)$settings['temperature'];
            if ($temperature < 0 || $temperature > 2) {
                $errors['temperature'] = 'Temperature must be between 0 and 2';
            }
        }
        
        // Validate top_k
        if (isset($settings['top_k'])) {
            $topK = (int)$settings['top_k'];
            if ($topK < 1 || $topK > 20) {
                $errors['top_k'] = 'Top K must be between 1 and 20';
            }
        }
        
        // Validate system prompt length
        if (isset($settings['system_prompt'])) {
            if (strlen($settings['system_prompt']) > 2000) {
                $errors['system_prompt'] = 'System prompt must be less than 2000 characters';
            }
        }
        
        return $errors;
    }

    /**
     * Export settings for backup
     */
    public function export(): array
    {
        $settings = $this->getAll();
        unset($settings['id']); // Remove ID field
        unset($settings['created_at']); // Remove timestamps
        unset($settings['updated_at']);
        
        return $settings;
    }

    /**
     * Import settings from backup
     */
    public function import(array $settings): bool
    {
        // Validate settings first
        $errors = $this->validate($settings);
        if (!empty($errors)) {
            throw new Exception('Invalid settings: ' . implode(', ', $errors));
        }
        
        // Filter out unknown keys
        $allowedKeys = array_keys($this->getDefaultSettings());
        $filteredSettings = array_intersect_key($settings, array_flip($allowedKeys));
        
        return $this->updateMultiple($filteredSettings);
    }
} 