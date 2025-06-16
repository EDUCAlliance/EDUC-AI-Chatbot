<?php

declare(strict_types=1);

namespace EducBot\Models;

use PDO;
use Exception;
use EducBot\Helpers\Logger;

/**
 * Room Configuration Model
 * 
 * Manages room-specific settings, onboarding state, and conversation configuration
 */
class RoomConfig
{
    private string $roomToken;
    private bool $isGroup;
    private string $mentionMode;
    private bool $onboardingDone;
    private int $onboardingStage;
    private ?string $customPrompt;
    private bool $enabled;
    private array $meta;
    private static ?PDO $db = null;

    public function __construct(array $data)
    {
        $this->roomToken = $data['room_token'];
        $this->isGroup = $data['is_group'] ?? false;
        $this->mentionMode = $data['mention_mode'] ?? 'on_mention';
        $this->onboardingDone = $data['onboarding_done'] ?? false;
        $this->onboardingStage = $data['onboarding_stage'] ?? 0;
        $this->customPrompt = $data['custom_prompt'] ?? null;
        $this->enabled = $data['enabled'] ?? true;
        
        // Handle JSONB meta field
        if (is_string($data['meta'] ?? '')) {
            $this->meta = json_decode($data['meta'], true) ?? [];
        } else {
            $this->meta = $data['meta'] ?? [];
        }

        if (self::$db === null) {
            self::$db = getDbConnection();
        }
    }

    /**
     * Find room config by token
     */
    public static function findByToken(string $roomToken): self
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }

        $stmt = self::$db->prepare('SELECT * FROM bot_room_config WHERE room_token = ?');
        $stmt->execute([$roomToken]);
        $data = $stmt->fetch();

        if ($data === false) {
            throw new Exception("Room config not found for token: {$roomToken}");
        }

        return new self($data);
    }

    /**
     * Create new room configuration
     */
    public static function create(string $roomToken, array $options = []): self
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }

        $data = [
            'room_token' => $roomToken,
            'is_group' => $options['is_group'] ?? false,
            'mention_mode' => $options['mention_mode'] ?? 'on_mention',
            'onboarding_done' => false,
            'onboarding_stage' => 0,
            'custom_prompt' => $options['custom_prompt'] ?? null,
            'enabled' => $options['enabled'] ?? true,
            'meta' => json_encode($options['meta'] ?? [])
        ];

        try {
            $stmt = self::$db->prepare('
                INSERT INTO bot_room_config 
                (room_token, is_group, mention_mode, onboarding_done, onboarding_stage, custom_prompt, enabled, meta)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['room_token'],
                $data['is_group'],
                $data['mention_mode'],
                $data['onboarding_done'],
                $data['onboarding_stage'],
                $data['custom_prompt'],
                $data['enabled'],
                $data['meta']
            ]);

            Logger::info('Room config created', ['room_token' => $roomToken]);
            return new self($data);

        } catch (Exception $e) {
            Logger::error('Failed to create room config', [
                'room_token' => $roomToken,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Save current state to database
     */
    public function save(): bool
    {
        try {
            $stmt = self::$db->prepare('
                UPDATE bot_room_config SET
                    is_group = ?,
                    mention_mode = ?,
                    onboarding_done = ?,
                    onboarding_stage = ?,
                    custom_prompt = ?,
                    enabled = ?,
                    meta = ?
                WHERE room_token = ?
            ');

            $result = $stmt->execute([
                $this->isGroup,
                $this->mentionMode,
                $this->onboardingDone,
                $this->onboardingStage,
                $this->customPrompt,
                $this->enabled,
                json_encode($this->meta),
                $this->roomToken
            ]);

            if ($result) {
                Logger::debug('Room config saved', ['room_token' => $this->roomToken]);
            }

            return $result;

        } catch (Exception $e) {
            Logger::error('Failed to save room config', [
                'room_token' => $this->roomToken,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Getters
    public function getRoomToken(): string
    {
        return $this->roomToken;
    }

    public function isGroup(): bool
    {
        return $this->isGroup;
    }

    public function getMentionMode(): string
    {
        return $this->mentionMode;
    }

    public function isOnboardingDone(): bool
    {
        return $this->onboardingDone;
    }

    public function getOnboardingStage(): int
    {
        return $this->onboardingStage;
    }

    public function getCustomPrompt(): ?string
    {
        return $this->customPrompt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMeta(string $key = null)
    {
        if ($key === null) {
            return $this->meta;
        }
        return $this->meta[$key] ?? null;
    }

    // Setters
    public function setIsGroup(bool $isGroup): self
    {
        $this->isGroup = $isGroup;
        return $this;
    }

    public function setMentionMode(string $mentionMode): self
    {
        if (!in_array($mentionMode, ['always', 'on_mention'])) {
            throw new Exception("Invalid mention mode: {$mentionMode}");
        }
        $this->mentionMode = $mentionMode;
        return $this;
    }

    public function setOnboardingDone(bool $done): self
    {
        $this->onboardingDone = $done;
        return $this;
    }

    public function setOnboardingStage(int $stage): self
    {
        $this->onboardingStage = $stage;
        return $this;
    }

    public function setCustomPrompt(?string $prompt): self
    {
        $this->customPrompt = $prompt;
        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function setMeta(string $key, $value): self
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function unsetMeta(string $key): self
    {
        unset($this->meta[$key]);
        return $this;
    }

    /**
     * Advance onboarding to next stage
     */
    public function advanceOnboarding(): self
    {
        $this->onboardingStage++;
        return $this;
    }

    /**
     * Complete onboarding process
     */
    public function completeOnboarding(): self
    {
        $this->onboardingDone = true;
        $this->onboardingStage = 0; // Reset stage counter
        return $this;
    }

    /**
     * Reset onboarding process
     */
    public function resetOnboarding(): self
    {
        $this->onboardingDone = false;
        $this->onboardingStage = 0;
        $this->meta = []; // Clear onboarding answers
        return $this;
    }

    /**
     * Store onboarding answer
     */
    public function storeOnboardingAnswer(int $stage, string $question, string $answer): self
    {
        if (!isset($this->meta['onboarding_answers'])) {
            $this->meta['onboarding_answers'] = [];
        }
        
        $this->meta['onboarding_answers'][$stage] = [
            'question' => $question,
            'answer' => $answer,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this;
    }

    /**
     * Get onboarding answers
     */
    public function getOnboardingAnswers(): array
    {
        return $this->meta['onboarding_answers'] ?? [];
    }

    /**
     * Get onboarding context as text for AI prompt
     */
    public function getOnboardingContext(): string
    {
        $answers = $this->getOnboardingAnswers();
        if (empty($answers)) {
            return '';
        }

        $context = "Onboarding information:\n";
        foreach ($answers as $stage => $data) {
            $context .= "Q: {$data['question']}\n";
            $context .= "A: {$data['answer']}\n\n";
        }

        return trim($context);
    }

    /**
     * Get all room configurations
     */
    public static function getAll(int $limit = 100, int $offset = 0): array
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }

        $stmt = self::$db->prepare('
            SELECT * FROM bot_room_config 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$limit, $offset]);

        $configs = [];
        while ($row = $stmt->fetch()) {
            $configs[] = new self($row);
        }

        return $configs;
    }

    /**
     * Count total room configurations
     */
    public static function count(): int
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }

        $stmt = self::$db->query('SELECT COUNT(*) FROM bot_room_config');
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get rooms that need onboarding
     */
    public static function getPendingOnboarding(): array
    {
        if (self::$db === null) {
            self::$db = getDbConnection();
        }

        $stmt = self::$db->query('
            SELECT * FROM bot_room_config 
            WHERE onboarding_done = false 
            ORDER BY created_at ASC
        ');

        $configs = [];
        while ($row = $stmt->fetch()) {
            $configs[] = new self($row);
        }

        return $configs;
    }

    /**
     * Delete room configuration
     */
    public function delete(): bool
    {
        try {
            $stmt = self::$db->prepare('DELETE FROM bot_room_config WHERE room_token = ?');
            $result = $stmt->execute([$this->roomToken]);

            if ($result) {
                Logger::info('Room config deleted', ['room_token' => $this->roomToken]);
            }

            return $result;

        } catch (Exception $e) {
            Logger::error('Failed to delete room config', [
                'room_token' => $this->roomToken,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'room_token' => $this->roomToken,
            'is_group' => $this->isGroup,
            'mention_mode' => $this->mentionMode,
            'onboarding_done' => $this->onboardingDone,
            'onboarding_stage' => $this->onboardingStage,
            'custom_prompt' => $this->customPrompt,
            'enabled' => $this->enabled,
            'meta' => $this->meta
        ];
    }
} 