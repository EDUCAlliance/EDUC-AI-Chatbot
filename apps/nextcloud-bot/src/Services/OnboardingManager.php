<?php

declare(strict_types=1);

namespace EducBot\Services;

use EducBot\Models\RoomConfig;
use EducBot\Models\Settings;
use EducBot\Helpers\Logger;
use Exception;

/**
 * Onboarding Manager Service
 * 
 * Handles guided onboarding dialogue flow for new chat rooms,
 * collecting configuration and context information from users.
 */
class OnboardingManager
{
    private Settings $settings;

    public function __construct()
    {
        $this->settings = new Settings();
    }

    /**
     * Process onboarding message and return appropriate response
     */
    public function processMessage(string $message, RoomConfig $roomConfig): string
    {
        $currentStage = $roomConfig->getOnboardingStage();
        $isGroup = $roomConfig->isGroup();

        Logger::debug('Processing onboarding message', [
            'room_token' => $roomConfig->getRoomToken(),
            'stage' => $currentStage,
            'is_group' => $isGroup,
            'message' => substr($message, 0, 100)
        ]);

        // Handle the current stage
        switch ($currentStage) {
            case 0:
                return $this->handleGroupDetection($message, $roomConfig);
            
            case 1:
                if ($isGroup) {
                    return $this->handleMentionModeConfig($message, $roomConfig);
                } else {
                    // Skip mention mode for DMs, go to custom questions
                    return $this->handleCustomQuestions($message, $roomConfig);
                }
            
            default:
                return $this->handleCustomQuestions($message, $roomConfig);
        }
    }

    /**
     * Handle stage 0: Detect if this is a group or DM
     */
    private function handleGroupDetection(string $message, RoomConfig $roomConfig): string
    {
        $message = trim(strtolower($message));
        
        // Parse yes/no response
        $isGroup = $this->parseYesNo($message);
        
        if ($isGroup === null) {
            return "I need to know if this is a group chat or a direct message to configure myself properly. Please answer with 'yes' if this is a group chat, or 'no' if it's a direct message.";
        }

        // Store the answer
        $roomConfig->setIsGroup($isGroup);
        $roomConfig->storeOnboardingAnswer(0, "Is this a group chat?", $isGroup ? 'Yes' : 'No');
        $roomConfig->advanceOnboarding();
        $roomConfig->save();

        Logger::info('Group detection completed', [
            'room_token' => $roomConfig->getRoomToken(),
            'is_group' => $isGroup
        ]);

        if ($isGroup) {
            return "Great! I understand this is a group chat.\n\n" .
                   "Next question: Should I respond to every message in this group, or only when someone mentions me with " . 
                   $this->settings->getBotMention() . "?\n\n" .
                   "Please answer with:\n" .
                   "- 'every message' or 'always' to respond to all messages\n" .
                   "- 'mention only' or 'when mentioned' to respond only when mentioned";
        } else {
            // Skip mention mode for DMs and move to custom questions
            $roomConfig->setMentionMode('always'); // Always respond in DMs
            return $this->askNextCustomQuestion($roomConfig);
        }
    }

    /**
     * Handle stage 1: Configure mention mode for groups
     */
    private function handleMentionModeConfig(string $message, RoomConfig $roomConfig): string
    {
        $message = trim(strtolower($message));
        
        $mentionMode = 'on_mention'; // Default
        $responseText = '';
        
        if (strpos($message, 'every') !== false || strpos($message, 'always') !== false || strpos($message, 'all') !== false) {
            $mentionMode = 'always';
            $responseText = 'every message';
        } elseif (strpos($message, 'mention') !== false || strpos($message, 'when') !== false) {
            $mentionMode = 'on_mention';
            $responseText = 'only when mentioned';
        } else {
            return "I didn't quite understand your preference. Please tell me:\n\n" .
                   "- Type 'every message' if you want me to respond to all messages\n" .
                   "- Type 'mention only' if you want me to respond only when mentioned with " . 
                   $this->settings->getBotMention();
        }

        // Store the configuration
        $roomConfig->setMentionMode($mentionMode);
        $roomConfig->storeOnboardingAnswer(1, "Response mode preference", $responseText);
        $roomConfig->advanceOnboarding();
        $roomConfig->save();

        Logger::info('Mention mode configured', [
            'room_token' => $roomConfig->getRoomToken(),
            'mention_mode' => $mentionMode
        ]);

        return "Perfect! I'll respond to {$responseText} in this group.\n\n" .
               $this->askNextCustomQuestion($roomConfig);
    }

    /**
     * Handle custom onboarding questions
     */
    private function handleCustomQuestions(string $message, RoomConfig $roomConfig): string
    {
        $currentStage = $roomConfig->getOnboardingStage();
        $isGroup = $roomConfig->isGroup();
        
        // Get the questions for this room type
        $questions = $this->settings->getOnboardingQuestions($isGroup);
        
        // Calculate which custom question we're on (subtract initial stages)
        $questionIndex = $isGroup ? ($currentStage - 2) : ($currentStage - 1);
        
        // If this isn't the first custom question, store the previous answer
        if ($questionIndex > 0) {
            $prevQuestionIndex = $questionIndex - 1;
            if (isset($questions[$prevQuestionIndex])) {
                $roomConfig->storeOnboardingAnswer(
                    $currentStage - 1,
                    $questions[$prevQuestionIndex],
                    trim($message)
                );
            }
        }

        // Advance to next stage
        $roomConfig->advanceOnboarding();
        $roomConfig->save();

        return $this->askNextCustomQuestion($roomConfig);
    }

    /**
     * Ask the next custom question or complete onboarding
     */
    private function askNextCustomQuestion(RoomConfig $roomConfig): string
    {
        $currentStage = $roomConfig->getOnboardingStage();
        $isGroup = $roomConfig->isGroup();
        
        // Get the questions for this room type
        $questions = $this->settings->getOnboardingQuestions($isGroup);
        
        // Calculate which custom question we're on
        $questionIndex = $isGroup ? ($currentStage - 2) : ($currentStage - 1);
        
        // Check if we have more questions
        if (isset($questions[$questionIndex])) {
            return $questions[$questionIndex];
        }

        // No more questions - complete onboarding
        return $this->completeOnboarding($roomConfig);
    }

    /**
     * Complete the onboarding process
     */
    private function completeOnboarding(RoomConfig $roomConfig): string
    {
        $roomConfig->completeOnboarding();
        $roomConfig->save();

        Logger::info('Onboarding completed', [
            'room_token' => $roomConfig->getRoomToken(),
            'is_group' => $roomConfig->isGroup(),
            'mention_mode' => $roomConfig->getMentionMode()
        ]);

        // Generate personalized welcome message based on onboarding answers
        $welcomeMessage = $this->generateWelcomeMessage($roomConfig);
        
        return $welcomeMessage;
    }

    /**
     * Generate personalized welcome message based on onboarding answers
     */
    private function generateWelcomeMessage(RoomConfig $roomConfig): string
    {
        $isGroup = $roomConfig->isGroup();
        $mentionMode = $roomConfig->getMentionMode();
        $botMention = $this->settings->getBotMention();
        
        $message = "🎉 **Welcome to EDUC AI!**\n\n";
        
        if ($isGroup) {
            $message .= "I'm now active in this group chat and ready to help! ";
            if ($mentionMode === 'always') {
                $message .= "I'll respond to every message in this group.";
            } else {
                $message .= "I'll respond when you mention me with {$botMention}.";
            }
        } else {
            $message .= "I'm ready to help you with your questions and provide assistance.";
        }

        $message .= "\n\n**Here's what I can help you with:**\n";
        $message .= "• 📚 Educational content and learning resources\n";
        $message .= "• 💡 Academic research and collaboration\n";
        $message .= "• 🔍 Information from my knowledge base\n";
        $message .= "• 📝 Writing and documentation assistance\n";
        $message .= "• 🤖 General questions and conversations\n\n";

        // Add context from onboarding if available
        $context = $this->generateContextFromAnswers($roomConfig);
        if (!empty($context)) {
            $message .= "**Based on our conversation, I understand:**\n{$context}\n\n";
        }

        $message .= "Feel free to ask me anything or say hello to get started! 👋";

        return $message;
    }

    /**
     * Generate context summary from onboarding answers
     */
    private function generateContextFromAnswers(RoomConfig $roomConfig): string
    {
        $answers = $roomConfig->getOnboardingAnswers();
        if (empty($answers)) {
            return '';
        }

        $context = '';
        foreach ($answers as $data) {
            if (!empty($data['answer']) && strlen($data['answer']) > 2) {
                $context .= "• {$data['answer']}\n";
            }
        }

        return trim($context);
    }

    /**
     * Parse yes/no response with various formats
     */
    private function parseYesNo(string $message): ?bool
    {
        $message = trim(strtolower($message));
        
        // Direct yes/no
        if (in_array($message, ['yes', 'y', 'yeah', 'yep', 'true', '1'])) {
            return true;
        }
        
        if (in_array($message, ['no', 'n', 'nope', 'false', '0'])) {
            return false;
        }
        
        // Check for keywords
        if (strpos($message, 'yes') !== false || strpos($message, 'group') !== false) {
            return true;
        }
        
        if (strpos($message, 'no') !== false || strpos($message, 'direct') !== false || strpos($message, 'dm') !== false) {
            return false;
        }
        
        return null; // Unclear response
    }

    /**
     * Reset onboarding for a room
     */
    public function resetOnboarding(RoomConfig $roomConfig): string
    {
        $roomConfig->resetOnboarding();
        $roomConfig->save();

        Logger::info('Onboarding reset', [
            'room_token' => $roomConfig->getRoomToken()
        ]);

        return "I've reset the onboarding process. Let me ask you a few questions to configure myself for this conversation.\n\n" .
               "First, is this a group chat or a direct message? Please answer with 'yes' for group chat or 'no' for direct message.";
    }

    /**
     * Get onboarding progress for a room
     */
    public function getProgress(RoomConfig $roomConfig): array
    {
        $isGroup = $roomConfig->isGroup();
        $currentStage = $roomConfig->getOnboardingStage();
        $isDone = $roomConfig->isOnboardingDone();
        
        if ($isDone) {
            return [
                'completed' => true,
                'stage' => 'completed',
                'progress' => 100,
                'answers' => $roomConfig->getOnboardingAnswers()
            ];
        }

        // Calculate total stages
        $totalStages = 1; // Group detection
        if ($isGroup) {
            $totalStages++; // Mention mode
        }
        $customQuestions = $this->settings->getOnboardingQuestions($isGroup);
        $totalStages += count($customQuestions);

        $progress = round(($currentStage / $totalStages) * 100);

        return [
            'completed' => false,
            'stage' => $currentStage,
            'total_stages' => $totalStages,
            'progress' => $progress,
            'answers' => $roomConfig->getOnboardingAnswers()
        ];
    }

    /**
     * Preview onboarding questions
     */
    public function previewQuestions(bool $isGroup = true): array
    {
        $questions = [];
        
        // Always start with group detection
        $questions[] = [
            'stage' => 0,
            'question' => 'Is this a group chat (yes/no)?',
            'type' => 'boolean'
        ];
        
        if ($isGroup) {
            $questions[] = [
                'stage' => 1,
                'question' => 'Should I respond to every message or only when mentioned?',
                'type' => 'choice',
                'options' => ['every message', 'mention only']
            ];
        }
        
        // Add custom questions
        $customQuestions = $this->settings->getOnboardingQuestions($isGroup);
        foreach ($customQuestions as $index => $question) {
            $stage = $isGroup ? ($index + 2) : ($index + 1);
            $questions[] = [
                'stage' => $stage,
                'question' => $question,
                'type' => 'text'
            ];
        }
        
        return $questions;
    }

    /**
     * Validate onboarding configuration
     */
    public function validateConfig(): array
    {
        $errors = [];
        
        try {
            $groupQuestions = $this->settings->getOnboardingQuestions(true);
            $dmQuestions = $this->settings->getOnboardingQuestions(false);
            
            if (empty($groupQuestions)) {
                $errors[] = 'No onboarding questions configured for group chats';
            }
            
            if (empty($dmQuestions)) {
                $errors[] = 'No onboarding questions configured for direct messages';
            }
            
            // Check for excessively long questions
            foreach ($groupQuestions as $question) {
                if (strlen($question) > 200) {
                    $errors[] = 'Group question too long: ' . substr($question, 0, 50) . '...';
                }
            }
            
            foreach ($dmQuestions as $question) {
                if (strlen($question) > 200) {
                    $errors[] = 'DM question too long: ' . substr($question, 0, 50) . '...';
                }
            }
            
        } catch (Exception $e) {
            $errors[] = 'Failed to validate onboarding config: ' . $e->getMessage();
        }
        
        return $errors;
    }
} 