<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConfigRepository;

/**
 * Platform-wide on/off switch for the in-chat usage taximeter
 * (consumption bar / ring + per-message token-cost badge).
 *
 * Mirrors {@see MarketingNews\MarketingNewsConfig}: a single
 * global master switch (BCONFIG group {@see self::CONFIG_GROUP}, setting
 * {@see self::KEY_ENABLED}, ownerId 0). The one deliberate difference is the
 * default: the taximeter is a transparency feature and defaults to ON when no
 * row exists, so a fresh install shows it out of the box.
 *
 * Read at exactly two seams:
 *   - the public runtime-config response (frontend gate), and
 *   - the SSE `complete` `usage_totals` branch (so the backend also skips the
 *     two daily SUM queries while the switch is off).
 *
 * The per-message `usage` payload, the history serialization, the usage
 * summary endpoint and the `/statistics` page are all switch-independent —
 * they surface the user's own data regardless of this display toggle.
 */
final readonly class UsageTaximeterConfig
{
    public const CONFIG_GROUP = 'USAGE_TAXIMETER';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = true;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Global-only (ownerId=0); defaults ON when no row exists.
     *
     * Accepts both the seeder convention ('1'/'0') and the admin UI convention
     * ('true'/'false'), like {@see MarketingNewsConfig}. A garbage value falls
     * back to the built-in default (ON) rather than silently hiding the feature.
     */
    public function isEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null === $value) {
            return self::DEFAULT_ENABLED;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }
}
