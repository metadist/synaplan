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
    public const PLATFORM_AWS = 'aws';

    public const GUIDE_URL_SELFHOST = 'https://github.com/metadist/synaplan/blob/main/docs/UPDATE_SELFHOST.md';
    public const GUIDE_URL_ELESTIO = 'https://github.com/metadist/synaplan/blob/main/docs/UPDATE_ELESTIO.md';
    public const GUIDE_URL_AWS = 'https://github.com/metadist/synaplan/blob/main/docs/UPDATE_AWS.md';

    private const GUIDES = [
        self::PLATFORM_SELFHOST => self::GUIDE_URL_SELFHOST,
        self::PLATFORM_ELESTIO => self::GUIDE_URL_ELESTIO,
        self::PLATFORM_AWS => self::GUIDE_URL_AWS,
    ];

    public function __construct(
        private string $platform,
    ) {
    }

    /**
     * The configured platform, normalised to a known value.
     */
    public function platform(): string
    {
        $configured = strtolower(trim($this->platform));

        return isset(self::GUIDES[$configured]) ? $configured : self::PLATFORM_SELFHOST;
    }

    public function guideUrl(): string
    {
        return self::GUIDES[$this->platform()];
    }
}
