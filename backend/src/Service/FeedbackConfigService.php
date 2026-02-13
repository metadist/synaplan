<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConfigRepository;

/**
 * Runtime-configurable feedback/search settings backed by BCONFIG database.
 *
 * Falls back to FeedbackConstants defaults when no DB value is set.
 * Values are cached per-request to avoid repeated DB queries.
 */
final class FeedbackConfigService
{
    private const GROUP = 'QDRANT_SEARCH';
    private const OWNER_ID = 0; // system-wide

    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly ConfigRepository $configRepository,
    ) {
    }

    // --- Score thresholds ---

    public function getMinChatFeedbackScore(): float
    {
        return $this->getFloat('MIN_CHAT_FEEDBACK_SCORE', FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE);
    }

    public function getMinChatMemoryScore(): float
    {
        return $this->getFloat('MIN_CHAT_MEMORY_SCORE', FeedbackConstants::MIN_CHAT_MEMORY_SCORE);
    }

    public function getMinContradictionScore(): float
    {
        return $this->getFloat('MIN_CONTRADICTION_SCORE', FeedbackConstants::MIN_CONTRADICTION_SCORE);
    }

    public function getMinResearchScore(): float
    {
        return $this->getFloat('MIN_RESEARCH_SCORE', FeedbackConstants::MIN_RESEARCH_SCORE);
    }

    public function getMinMemoryResearchScore(): float
    {
        return $this->getFloat('MIN_MEMORY_RESEARCH_SCORE', FeedbackConstants::MIN_MEMORY_RESEARCH_SCORE);
    }

    public function getMinExtractionScore(): float
    {
        return $this->getFloat('MIN_EXTRACTION_SCORE', FeedbackConstants::MIN_EXTRACTION_SCORE);
    }

    // --- Limits ---

    public function getLimitPerNamespace(): int
    {
        return $this->getInt('LIMIT_PER_NAMESPACE', FeedbackConstants::LIMIT_PER_NAMESPACE);
    }

    public function getMaxChatMemories(): int
    {
        return $this->getInt('MAX_CHAT_MEMORIES', 10);
    }

    // --- Helpers ---

    private function getFloat(string $setting, float $default): float
    {
        $raw = $this->getCached($setting);

        if (null === $raw || '' === $raw) {
            return $default;
        }

        $value = (float) $raw;

        return ($value >= 0.0 && $value <= 1.0) ? $value : $default;
    }

    private function getInt(string $setting, int $default): int
    {
        $raw = $this->getCached($setting);

        if (null === $raw || '' === $raw) {
            return $default;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : $default;
    }

    private function getCached(string $setting): ?string
    {
        if (!\array_key_exists($setting, $this->cache)) {
            $this->cache[$setting] = $this->configRepository->getValue(self::OWNER_ID, self::GROUP, $setting);
        }

        return $this->cache[$setting];
    }
}
