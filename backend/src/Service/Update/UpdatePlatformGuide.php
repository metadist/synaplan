<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Resolves the deployment platform hint (SYNAPLAN_PLATFORM) to the manual-update
 * guide an admin should follow.
 *
 * Synaplan never updates itself: the notice only links to documentation, and the
 * operator performs the update. Which document that is depends on how the
 * instance was deployed, which only the deployment can know — hence the
 * optional environment hint, defaulting to the self-hosted guide so an unset or
 * unknown value can never produce a broken link.
 */
final readonly class UpdatePlatformGuide
{
    public const PLATFORM_SELFHOST = 'selfhost';
    public const PLATFORM_ELESTIO = 'elestio';

    public const GUIDE_URL_SELFHOST = 'https://github.com/metadist/synaplan/blob/main/docs/UPDATE_SELFHOST.md';
    public const GUIDE_URL_ELESTIO = 'https://github.com/metadist/synaplan/blob/main/docs/UPDATE_ELESTIO.md';

    public function __construct(
        private string $platform,
    ) {
    }

    /**
     * The configured platform, normalised to a known value.
     */
    public function platform(): string
    {
        return self::PLATFORM_ELESTIO === strtolower(trim($this->platform))
            ? self::PLATFORM_ELESTIO
            : self::PLATFORM_SELFHOST;
    }

    public function guideUrl(): string
    {
        return match ($this->platform()) {
            self::PLATFORM_ELESTIO => self::GUIDE_URL_ELESTIO,
            default => self::GUIDE_URL_SELFHOST,
        };
    }
}
