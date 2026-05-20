<?php

namespace App\Service;

use App\Entity\Prompt;
use App\Prompt\RoutingTopicPolicy;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for loading prompts with their metadata (AI model, tools, etc.).
 */
final readonly class PromptService
{
    public function __construct(
        private PromptRepository $promptRepository,
        private PromptMetaRepository $promptMetaRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get prompt with metadata by topic and user.
     *
     * When $topic is a canonical handler key (`general`, `mediamaker`), the
     * matching granular routing row is preferred for editable content.
     *
     * @param string      $topic         Topic identifier (usually canonical handler key)
     * @param int         $userId        User ID (0 = only system prompts)
     * @param string      $lang          Language code (not used for filtering, just for logging)
     * @param string|null $granularTopic Granular routing topic from classification, if any
     * @param string|null $mediaType     Media subtype hint for mediamaker handler lookups
     *
     * @return array|null ['prompt' => Prompt, 'metadata' => array] or null
     */
    public function getPromptWithMetadata(
        string $topic,
        int $userId = 0,
        string $lang = 'en',
        ?string $granularTopic = null,
        ?string $mediaType = null,
    ): ?array {
        foreach (RoutingTopicPolicy::promptLookupTopics($topic, $granularTopic, $mediaType) as $lookupTopic) {
            $prompt = $this->promptRepository->findByTopicAndUser($lookupTopic, $userId);

            if (!$prompt) {
                continue;
            }

            $metadata = $this->loadMetadataForPrompt($prompt->getId());

            if ($lookupTopic !== strtolower(trim($topic))) {
                $this->logger->debug('PromptService: Loaded prompt via routing topic alias', [
                    'requested_topic' => $topic,
                    'lookup_topic' => $lookupTopic,
                    'granular_topic' => $granularTopic,
                    'user_id' => $userId,
                ]);
            }

            return [
                'prompt' => $prompt,
                'metadata' => $metadata,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array|null ['prompt' => Prompt, 'metadata' => array] or null
     */
    public function getPromptForClassification(array $classification, int $userId): ?array
    {
        $granularTopic = isset($classification['granular_topic']) ? (string) $classification['granular_topic'] : null;
        $mediaType = isset($classification['media_type']) ? (string) $classification['media_type'] : null;

        return $this->getPromptWithMetadata(
            (string) ($classification['topic'] ?? 'general'),
            $userId,
            (string) ($classification['language'] ?? 'en'),
            $granularTopic,
            $mediaType,
        );
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
            'aiModel' => -1, // -1 = no specific model set, frontend defaults to gpt-oss-120b
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
