<?php

declare(strict_types=1);

namespace App\Service\Context;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;

/**
 * Operator knobs for fitting attachments and fetched pages into a model's
 * context window. BCONFIG group {@see self::CONFIG_GROUP}; every key is
 * optional — an absent row means the built-in default, so no migration is
 * needed to roll the defaults out.
 *
 *   - CONDENSE_ENABLED     — master switch for the stacked condensation loop.
 *                            OFF = hard trim to the budget (never overflow).
 *   - INPUT_SHARE_PERCENT  — how much of the model's input window ONE
 *                            attachment block may take (default 55 %). The
 *                            rest is headroom for the system prompt, history,
 *                            search context and the tokenizer estimate error.
 *   - MAX_LEVELS           — how many map-reduce rounds the condenser may run
 *                            before it hard-trims (default 3).
 *   - CONDENSER_CHUNK_TOKENS — target chunk size for one condenser call
 *                            (default 24k tokens — comfortably inside the
 *                            131k window of the usual SORT/ANALYZE models).
 *   - ROUTING_FULL_TEXT_MAX_CHARS — attachments up to this size reach the
 *                            SORT / PLAN models verbatim, exactly as before.
 *   - ROUTING_DIGEST_CHARS — size of the digest that replaces the file text
 *                            for the routing models above that threshold.
 */
final readonly class ContextFittingConfig
{
    public const CONFIG_GROUP = 'CONTEXT';

    public const KEY_CONDENSE_ENABLED = 'CONDENSE_ENABLED';
    public const KEY_INPUT_SHARE_PERCENT = 'INPUT_SHARE_PERCENT';
    public const KEY_MAX_LEVELS = 'MAX_LEVELS';
    public const KEY_CONDENSER_CHUNK_TOKENS = 'CONDENSER_CHUNK_TOKENS';
    public const KEY_ROUTING_FULL_TEXT_MAX_CHARS = 'ROUTING_FULL_TEXT_MAX_CHARS';
    public const KEY_ROUTING_DIGEST_CHARS = 'ROUTING_DIGEST_CHARS';

    public const DEFAULT_CONDENSE_ENABLED = true;
    public const DEFAULT_INPUT_SHARE_PERCENT = 55;
    public const DEFAULT_MAX_LEVELS = 3;
    public const DEFAULT_CONDENSER_CHUNK_TOKENS = 24000;
    public const DEFAULT_ROUTING_FULL_TEXT_MAX_CHARS = 12000;
    public const DEFAULT_ROUTING_DIGEST_CHARS = 1800;

    /** Conservative window when the catalog row carries no `meta.context_window`. */
    public const FALLBACK_CONTEXT_TOKENS = 128000;

    /** Output reservation when the catalog row carries no `meta.max_output`. */
    public const FALLBACK_MAX_OUTPUT_TOKENS = 8192;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
    ) {
    }

    public function isCondenseEnabled(?int $userId): bool
    {
        return $this->resolveBool(self::KEY_CONDENSE_ENABLED, $userId, self::DEFAULT_CONDENSE_ENABLED);
    }

    /** Share of the input window one attachment block may occupy, 0.10 … 0.90. */
    public function inputShare(?int $userId): float
    {
        $percent = $this->resolveInt(self::KEY_INPUT_SHARE_PERCENT, $userId, self::DEFAULT_INPUT_SHARE_PERCENT);

        return max(10, min(90, $percent)) / 100;
    }

    public function maxLevels(?int $userId): int
    {
        return max(1, min(5, $this->resolveInt(self::KEY_MAX_LEVELS, $userId, self::DEFAULT_MAX_LEVELS)));
    }

    public function condenserChunkTokens(?int $userId): int
    {
        return max(4000, min(100000, $this->resolveInt(self::KEY_CONDENSER_CHUNK_TOKENS, $userId, self::DEFAULT_CONDENSER_CHUNK_TOKENS)));
    }

    public function routingFullTextMaxChars(?int $userId): int
    {
        return max(2000, min(200000, $this->resolveInt(self::KEY_ROUTING_FULL_TEXT_MAX_CHARS, $userId, self::DEFAULT_ROUTING_FULL_TEXT_MAX_CHARS)));
    }

    public function routingDigestChars(?int $userId): int
    {
        return max(400, min(8000, $this->resolveInt(self::KEY_ROUTING_DIGEST_CHARS, $userId, self::DEFAULT_ROUTING_DIGEST_CHARS)));
    }

    private function resolveBool(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, $setting, $default);
        }

        $raw = $this->rawValue($setting, $userId);
        if (null === $raw) {
            return $default;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function resolveInt(string $setting, ?int $userId, int $default): int
    {
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveInt($userId, self::CONFIG_GROUP, $setting, $default);
        }

        $raw = $this->rawValue($setting, $userId);
        if (null === $raw || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }

    private function rawValue(string $setting, ?int $userId): ?string
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return $perUser;
            }
        }

        return $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
    }
}
