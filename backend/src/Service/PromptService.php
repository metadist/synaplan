<?php

namespace App\Service;

use App\Entity\Prompt;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for loading prompts with their metadata (AI model, tools, etc.).
 *
 * ## Metadata key naming
 *
 * The backend's canonical tool-flag keys are `tool_internet` and
 * `tool_files`. Historic frontend builds wrote `tool_internet_search` /
 * `tool_files_search` instead; both aliases are accepted on read AND on
 * write and folded onto the canonical names by {@see self::METADATA_KEY_ALIASES}.
 * This keeps existing `BPROMPTMETA` rows readable while new writes always
 * land on the canonical key — and prevents the router from silently
 * ignoring the user-facing "Internet Search" toggle.
 */
final readonly class PromptService
{
    /**
     * Legacy → canonical metadata key aliases.
     *
     * Historically the Vue config wrote `tool_internet_search` /
     * `tool_files_search` while the routing layer read `tool_internet` /
     * `tool_files`, so the per-prompt "Internet Search" toggle never
     * actually enabled web search in `MessageProcessor`. Both names are
     * now folded onto the backend-canonical short form here, in one
     * place, on both the read and write paths.
     *
     * Keep additions sorted alphabetically.
     */
    private const METADATA_KEY_ALIASES = [
        'tool_files_search' => 'tool_files',
        'tool_internet_search' => 'tool_internet',
    ];

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

        // IMPORTANT: do NOT pre-populate `tool_*` keys with a default
        // boolean. Downstream routing (`WebSearchTopicPolicy::shouldSearch`)
        // discriminates between three states:
        //
        //   - true  → user explicitly opted in   → search
        //   - false → user explicitly opted out  → never search
        //   - null  (key absent) → no preference → search (project default)
        //
        // A pre-populated `tool_internet => false` would collapse the
        // "no preference" case onto "explicit opt-out" and silently
        // disable web search for every prompt that has never been
        // customised through the UI.
        $metadata = [
            'aiModel' => -1, // -1 = no specific model set, frontend defaults to gpt-oss-120b
        ];

        foreach ($metaEntries as $meta) {
            $rawKey = $meta->getMetaKey();
            $value = $meta->getMetaValue();
            $key = self::canonicalizeMetadataKey($rawKey);

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
     * Normalise a metadata key to its canonical backend name.
     *
     * Returns the input unchanged if it is not a known legacy alias.
     */
    public static function canonicalizeMetadataKey(string $key): string
    {
        return self::METADATA_KEY_ALIASES[$key] ?? $key;
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

        // Canonicalise the payload before persisting so a frontend that still
        // sends the legacy aliases (e.g. `tool_internet_search`) lands on the
        // backend-canonical key (`tool_internet`). If both the alias and the
        // canonical key are present, the canonical value wins.
        $normalised = $this->normaliseMetadataKeys($metadata);

        // Save new metadata
        foreach ($normalised as $key => $value) {
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
        if (!empty($normalised)) {
            $this->em->flush();
        }
    }

    /**
     * Fold legacy aliases onto canonical keys. If both are present the
     * canonical value wins, so an explicit `tool_internet` payload from the
     * frontend can never be silently overridden by a stale alias.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function normaliseMetadataKeys(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            $canonical = self::canonicalizeMetadataKey((string) $key);
            // Canonical entry written explicitly takes precedence over an alias.
            if ($canonical !== $key && array_key_exists($canonical, $metadata)) {
                continue;
            }
            $result[$canonical] = $value;
        }

        return $result;
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
