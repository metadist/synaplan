<?php

namespace App\Service;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use Psr\Log\LoggerInterface;

/**
 * PromptService
 * 
 * Ersetzt BasicAI::getAprompt() - lädt Prompts mit User-Override-Logik
 */
class PromptService
{
    public function __construct(
        private PromptRepository $promptRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Get prompt by topic (wie BasicAI::getAprompt)
     * 
     * @param string $topic z.B. 'general', 'tools:sort', 'tools:pic'
     * @param string $language 'en', 'de', etc.
     * @param int $userId User ID (0 = nur globale Prompts)
     * @return array Prompt-Daten
     */
    public function getPrompt(string $topic, string $language = 'en', int $userId = 0): array
    {
        $prompt = $this->promptRepository->findByTopicAndUser($topic, $language, $userId);

        if (!$prompt) {
            $this->logger->warning('Prompt not found, using fallback', [
                'topic' => $topic,
                'language' => $language,
                'userId' => $userId,
            ]);

            // Fallback wie im alten System
            return [
                'BID' => 0,
                'BTOPIC' => $topic,
                'BPROMPT' => 'You are a helpful AI assistant. Please help the user with their request.',
                'BLANG' => 'en',
                'BSHORTDESC' => 'Default prompt for ' . $topic,
            ];
        }

        return [
            'BID' => $prompt->getId(),
            'BTOPIC' => $prompt->getTopic(),
            'BPROMPT' => $prompt->getPrompt(),
            'BLANG' => $prompt->getLanguage(),
            'BSHORTDESC' => $prompt->getShortDescription(),
        ];
    }

    /**
     * Get all user-accessible prompts (nicht tools:*)
     */
    public function getAllPrompts(int $userId): array
    {
        $prompts = $this->promptRepository->findAllForUser($userId);
        
        $result = [];
        $seenTopics = [];

        foreach ($prompts as $prompt) {
            // Deduplizierung: User-specific überschreibt global
            if (!in_array($prompt->getTopic(), $seenTopics)) {
                $result[] = [
                    'BID' => $prompt->getId(),
                    'BTOPIC' => $prompt->getTopic(),
                    'BPROMPT' => $prompt->getPrompt(),
                    'BLANG' => $prompt->getLanguage(),
                    'BSHORTDESC' => $prompt->getShortDescription(),
                ];
                $seenTopics[] = $prompt->getTopic();
            }
        }

        return $result;
    }
}

