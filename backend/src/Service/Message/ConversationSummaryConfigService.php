<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Repository\ConfigRepository;

/**
 * Runtime-configurable settings for the rolling conversation summary, backed by
 * the BCONFIG database (group {@see ConversationSummaryConstants::CONFIG_GROUP},
 * owner 0 = system-wide).
 *
 * Falls back to {@see ConversationSummaryConstants} when no DB value is set, so
 * the feature works with zero seeded rows. Values are cached per-request to
 * avoid repeated DB queries (same pattern as FeedbackConfigService).
 */
final class ConversationSummaryConfigService
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        $raw = $this->getCached('ENABLED');

        if (null === $raw || '' === $raw) {
            return ConversationSummaryConstants::ENABLED;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Combined memory window (verbatim recent turns + summary), clamped to the
     * 10 000-15 000 character band the product requires.
     */
    public function getTargetWindowChars(): int
    {
        $value = $this->getInt('TARGET_WINDOW_CHARS', ConversationSummaryConstants::TARGET_WINDOW_CHARS);

        return max(
            ConversationSummaryConstants::MIN_WINDOW_CHARS,
            min(ConversationSummaryConstants::MAX_WINDOW_CHARS, $value),
        );
    }

    /**
     * Character budget reserved for the most recent turns kept verbatim.
     *
     * Never allowed to consume the whole window: the summary always keeps at
     * least a small slice so older context can still be represented.
     */
    public function getRecentVerbatimChars(): int
    {
        $window = $this->getTargetWindowChars();
        $value = $this->getInt('RECENT_VERBATIM_CHARS', ConversationSummaryConstants::RECENT_VERBATIM_CHARS);

        // Leave room for at least a minimal summary inside the window.
        return max(1000, min($window - 500, $value));
    }

    /**
     * Hard cap on the injected rolling summary. Kept so that
     * recentVerbatim + summary stays within the target window.
     */
    public function getSummaryMaxChars(): int
    {
        $window = $this->getTargetWindowChars();
        $recent = $this->getRecentVerbatimChars();
        $value = $this->getInt('SUMMARY_MAX_CHARS', ConversationSummaryConstants::SUMMARY_MAX_CHARS);

        return max(500, min($window - $recent, $value));
    }

    public function getMaxSourceMessages(): int
    {
        return $this->getInt('MAX_SOURCE_MESSAGES', ConversationSummaryConstants::MAX_SOURCE_MESSAGES);
    }

    public function getTiers(): int
    {
        $value = $this->getInt('TIERS', ConversationSummaryConstants::TIERS);

        return max(1, min(5, $value));
    }

    public function getCacheTtl(): int
    {
        return $this->getInt('CACHE_TTL', ConversationSummaryConstants::CACHE_TTL);
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
            $this->cache[$setting] = $this->configRepository->getValue(
                ConversationSummaryConstants::CONFIG_OWNER_ID,
                ConversationSummaryConstants::CONFIG_GROUP,
                $setting,
            );
        }

        return $this->cache[$setting];
    }
}
