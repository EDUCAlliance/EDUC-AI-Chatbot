<?php

declare(strict_types=1);

namespace NextcloudBot\Services;

use PDO;
use NextcloudBot\Helpers\Logger;

class OnboardingManager
{
    private PDO $db;
    private ?Logger $logger;

    public function __construct(PDO $db, Logger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Gets the next onboarding question for a given room.
     *
     * @param array $roomConfig The current configuration of the room.
     * @param array $globalSettings The global bot settings.
     * @return array A dictionary with 'question' and 'is_done'.
     */
    public function getNextQuestion(array $roomConfig, array $globalSettings): array
    {
        $stage = $roomConfig['meta']['stage'] ?? 0;
        $isGroup = $roomConfig['is_group'] ?? true;

        // Add debug logging
        error_log("OnboardingManager: stage={$stage}, isGroup=" . ($isGroup ? 'true' : 'false') . ", roomToken=" . ($roomConfig['room_token'] ?? 'unknown'));

        switch ($stage) {
            case 0:
                return ['question' => "Is this a group chat or a direct message? (Please answer with 'group' or 'dm')", 'is_done' => false];
            case 1:
                return ['question' => "Should I respond to every message, or only when I'm mentioned? (Please answer with 'always' or 'on_mention')", 'is_done' => false];
            default:
                // Handle custom questions from admin panel
                $customQuestionsRaw = $isGroup 
                    ? ($globalSettings['onboarding_group_questions'] ?? '[]')
                    : ($globalSettings['onboarding_dm_questions'] ?? '[]');
                
                // Add debug logging
                error_log("OnboardingManager: customQuestionsRaw=" . var_export($customQuestionsRaw, true));
                
                // Decode JSON if it's a string, otherwise use as array
                if (is_string($customQuestionsRaw)) {
                    $customQuestions = json_decode($customQuestionsRaw, true) ?: [];
                } else {
                    $customQuestions = $customQuestionsRaw;
                }
                
                error_log("OnboardingManager: customQuestions=" . var_export($customQuestions, true));
                
                $questionIndex = $stage - 2;
                error_log("OnboardingManager: questionIndex={$questionIndex}, available questions=" . count($customQuestions));
                
                if (isset($customQuestions[$questionIndex]) && !empty($customQuestions[$questionIndex])) {
                    error_log("OnboardingManager: returning custom question: " . $customQuestions[$questionIndex]);
                    return ['question' => $customQuestions[$questionIndex], 'is_done' => false];
                }

                // End of onboarding
                error_log("OnboardingManager: ending onboarding for room " . $roomConfig['room_token']);
                $this->markOnboardingAsDone($roomConfig['room_token']);
                
                $completionMessage = $globalSettings['onboarding_completion_message'] ?? "Thanks for setting me up! I'm ready to help.";
                return ['question' => $completionMessage, 'is_done' => true];
        }
    }

    /**
     * Processes a user's answer during the onboarding process.
     *
     * @param array $roomConfig The current room configuration.
     * @param string $answer The user's answer.
     * @return bool True if the answer was valid and stage was advanced, false otherwise.
     */
    public function processAnswer(array &$roomConfig, string $answer): bool
    {
        $stage = $roomConfig['meta']['stage'] ?? 0;
        $roomConfig['meta']['answers'] = $roomConfig['meta']['answers'] ?? [];
        $answer = strtolower(trim($answer));
        $validAnswer = false;

        switch ($stage) {
            case 0: // Is this a group or dm?
                if (in_array($answer, ['group', 'g'])) {
                    $roomConfig['is_group'] = true;
                    $validAnswer = true;
                } elseif (in_array($answer, ['dm', 'd', 'direct message'])) {
                    $roomConfig['is_group'] = false;
                    $validAnswer = true;
                }
                break;
            case 1: // Mention mode
                if (in_array($answer, ['always', 'a'])) {
                    $roomConfig['mention_mode'] = 'always';
                    $validAnswer = true;
                } elseif (in_array($answer, ['on_mention', 'mention', 'm'])) {
                    $roomConfig['mention_mode'] = 'on_mention';
                    $validAnswer = true;
                }
                break;
            default: // Custom questions - accept any non-empty answer
                if (!empty(trim($answer))) {
                    $roomConfig['meta']['answers'][] = $answer;
                    $validAnswer = true;
                }
                break;
        }

        // Only advance to the next stage if we got a valid answer
        if ($validAnswer) {
            $roomConfig['meta']['stage'] = ($stage + 1);
            $this->updateRoomConfig($roomConfig);
            return true;
        } else {
            // Update config without advancing stage (to persist any partial changes)
            $this->updateRoomConfig($roomConfig);
            return false;
        }
    }

    private function updateRoomConfig(array $roomConfig): void
    {
        $sql = "UPDATE bot_room_config SET 
                    is_group = :is_group,
                    mention_mode = :mention_mode,
                    meta = :meta
                WHERE room_token = :room_token";
        
        $stmt = $this->db->prepare($sql);

        // Explicitly bind the boolean value for PostgreSQL compatibility
        $stmt->bindValue(':is_group', $roomConfig['is_group'], \PDO::PARAM_BOOL);
        $stmt->bindValue(':mention_mode', $roomConfig['mention_mode'] ?? 'on_mention');
        $stmt->bindValue(':meta', json_encode($roomConfig['meta']));
        $stmt->bindValue(':room_token', $roomConfig['room_token']);

        $stmt->execute();
    }

    private function markOnboardingAsDone(string $roomToken): void
    {
        $sql = "UPDATE bot_room_config SET onboarding_done = TRUE WHERE room_token = :room_token";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':room_token' => $roomToken]);
    }
} 