<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Immutable, already-validated view of the published release manifest.
 *
 * Only {@see UpdateManifestClient} creates instances, and it only does so for a
 * payload that passed every structural check — so every consumer can rely on
 * `version` being a comparable version string, `notesUrl` being an http(s) URL
 * or null, and `severity` being one of the two known values.
 */
final readonly class ReleaseManifest
{
    public const SEVERITY_NORMAL = 'normal';
    public const SEVERITY_SECURITY = 'security';

    /**
     * @param list<string> $yankedVersions versions that must never be recommended
     */
    public function __construct(
        public string $version,
        public ?string $notesUrl,
        public string $severity,
        public ?string $releasedAt,
        public array $yankedVersions,
    ) {
    }

    /**
     * Whether the given version was withdrawn by the publisher.
     *
     * Compared with version_compare() rather than string equality, so a
     * withdrawn release cannot slip through because the publisher spelled its
     * separators differently ('4.1.0-rc.1' / '4.1.0.rc.1').
     */
    public function isYanked(string $version): bool
    {
        foreach ($this->yankedVersions as $yanked) {
            if (version_compare($version, $yanked, '==')) {
                return true;
            }
        }

        return false;
    }
}
