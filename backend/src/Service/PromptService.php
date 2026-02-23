<?php

namespace App\Service;

use App\Entity\Prompt;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for loading prompts with their metadata (AI model, tools, etc.).
 */
class PromptService
{
    public function __construct(
        private PromptRepository $promptRepository,
        private PromptMetaRepository $promptMetaRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get prompt with metadata by topic and user
     * Returns prompt with metadata loaded as array
     * Note: $lang parameter is kept for backward compatibility but NOT used for filtering.
     *
     * @param string $topic  Topic identifier
     * @param int    $userId User ID (0 = only system prompts)
     * @param string $lang   Language code (not used for filtering, just for logging)
     *
     * @return array|null ['prompt' => Prompt, 'metadata' => array] or null
     */
    public function getPromptWithMetadata(string $topic, int $userId = 0, string $lang = 'en'): ?array
    {
        // Get prompt (with user override support)
        // Language is NOT used as filter - it's just metadata
        $prompt = $this->promptRepository->findByTopicAndUser($topic, $userId);

        if (!$prompt) {
            return null;
        }

        // Load metadata
        $metadata = $this->loadMetadataForPrompt($prompt->getId());

        return [
            'prompt' => $prompt,
            'metadata' => $metadata,
        ];
    }

    /**
     * Load all metadata for a prompt.
     *
     * @param int $promptId Prompt ID
     *
     * @return array Metadata as key-value pairs
     */
    public function loadMetadataForPrompt(int $promptId): array
    {
        $metaEntries = $this->promptMetaRepository->findBy(['promptId' => $promptId]);

        $metadata = [
            'aiModel' => -1, // -1 = AUTOMATED
            'tool_internet' => false,
            'tool_files' => false,
            'tool_url_screenshot' => false,
            'tool_transfer' => false,
        ];

        foreach ($metaEntries as $meta) {
            $key = $meta->getMetaKey();
            $value = $meta->getMetaValue();

            // Convert boolean strings to actual booleans
            if (str_starts_with($key, 'tool_')) {
                $metadata[$key] = (bool) (int) $value;
            } elseif ('aiModel' === $key) {
                $metadata[$key] = (int) $value;
            } else {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Save metadata for a prompt.
     *
     * @param Prompt $prompt   The Prompt entity
     * @param array  $metadata Metadata as key-value pairs
     */
    public function saveMetadataForPrompt(Prompt $prompt, array $metadata): void
    {
        $promptId = $prompt->getId();
        if (!$promptId) {
            throw new \InvalidArgumentException('Prompt must have an ID before saving metadata');
        }

        // Delete existing metadata
        $existing = $this->promptMetaRepository->findBy(['promptId' => $promptId]);
        foreach ($existing as $meta) {
            $this->em->remove($meta);
        }

        // Flush deletions if any
        if (!empty($existing)) {
            $this->em->flush();
        }

        // Save new metadata
        foreach ($metadata as $key => $value) {
            $meta = new \App\Entity\PromptMeta();
            $meta->setPrompt($prompt);  // ✅ Use setPrompt() instead of setPromptId()
            $meta->setMetaKey($key);

            // Convert booleans to string "0" or "1"
            if (is_bool($value)) {
                $meta->setMetaValue($value ? '1' : '0');
            } else {
                $meta->setMetaValue((string) $value);
            }

            $this->em->persist($meta);
        }

        // Flush all new metadata at once
        if (!empty($metadata)) {
            $this->em->flush();
        }
    }

    /**
     * Get all prompts for a user with their metadata
     * Used for sorting to get ALL available task prompts.
     *
     * @param int    $userId User ID
     * @param string $lang   Language code
     *
     * @return array Array of ['prompt' => Prompt, 'metadata' => array]
     */
    public function getAllPromptsWithMetadata(int $userId, string $lang = 'en'): array
    {
        $prompts = $this->promptRepository->findAllForUser($userId, $lang);

        $result = [];
        foreach ($prompts as $prompt) {
            $result[] = [
                'prompt' => $prompt,
                'metadata' => $this->loadMetadataForPrompt($prompt->getId()),
            ];
        }

        return $result;
    }

    /**
     * Check if a message matches the selection rules of a prompt
     * Selection rules are simple text-based matching for now.
     *
     * @param string|null $selectionRules      Selection rules from prompt
     * @param string      $messageText         User's message text
     * @param array       $conversationHistory Previous messages (optional)
     *
     * @return bool True if rules match
     */
    public function matchesSelectionRules(?string $selectionRules, string $messageText, array $conversationHistory = []): bool
    {
        if (empty($selectionRules)) {
            return false; // No rules = never auto-select
        }

        $messageText = strtolower($messageText);
        $rules = strtolower($selectionRules);

        // Simple keyword matching (can be extended later with AI-based matching)
        // Split rules by newlines or commas
        $keywords = preg_split('/[,\n]+/', $rules);

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (empty($keyword)) {
                continue;
            }

            // Check if keyword appears in message
            if (str_contains($messageText, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
