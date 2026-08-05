<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Repository\ConfigRepository;

/**
 * Update-notice configuration and stored check result (BCONFIG group UPDATES,
 * ownerId 0).
 *
 * Two operator-owned settings — the master switch and the manifest URL (so
 * forks and air-gapped installs can repoint or disable the check) — plus the
 * result fields the daily check writes. The admin API only ever READS the
 * result fields, so no request path performs outbound HTTP.
 *
 * Every read has a built-in fallback for a MISSING row (and treats a
 * whitespace-only value as missing), because BCONFIG seeder defaults are
 * bootstrap-only: an install that upgraded into this feature has no UPDATES
 * rows at all until the next `app:seed`, and must still behave exactly like a
 * freshly seeded one.
 */
final readonly class UpdateConfig
{
    public const CONFIG_GROUP = 'UPDATES';
    public const OWNER_ID = 0;

    public const KEY_CHECK_ENABLED = 'CHECK_ENABLED';
    public const KEY_MANIFEST_URL = 'MANIFEST_URL';
    public const KEY_LATEST_VERSION = 'LATEST_VERSION';
    public const KEY_LATEST_NOTES_URL = 'LATEST_NOTES_URL';
    public const KEY_LATEST_SEVERITY = 'LATEST_SEVERITY';
    public const KEY_LATEST_RELEASED_AT = 'LATEST_RELEASED_AT';
    public const KEY_LAST_CHECKED_AT = 'LAST_CHECKED_AT';
    public const KEY_LAST_ERROR = 'LAST_ERROR';
    public const KEY_DISMISSED_VERSION = 'DISMISSED_VERSION';

    /**
     * Published manifest of the upstream project. A plain static file: the check
     * is a bare GET with no instance identifier, no telemetry and no query
     * parameters.
     */
    public const DEFAULT_MANIFEST_URL = 'https://raw.githubusercontent.com/metadist/synaplan/release-manifest/versions.json';

    /**
     * Detection is a display-only feature that never changes the installation,
     * so it defaults ON — an operator who does not want any outbound request
     * turns it off (or empties MANIFEST_URL).
     */
    private const DEFAULT_CHECK_ENABLED = true;

    /**
     * A recorded error is operator-facing context, not a log sink: cap it so a
     * server answering with a whole HTML page never lands in BCONFIG.
     */
    private const MAX_ERROR_LENGTH = 500;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Defaults ON when no row exists; accepts both the seeder
     * convention ('1'/'0') and the admin UI convention ('true'/'false'), and
     * falls back to the built-in default for a garbage value.
     */
    public function isCheckEnabled(): bool
    {
        $value = $this->read(self::KEY_CHECK_ENABLED);
        if (null === $value) {
            return self::DEFAULT_CHECK_ENABLED;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_CHECK_ENABLED;
    }

    /**
     * The manifest URL to fetch, or null when the configured value is not a
     * usable http(s) URL (an operator can empty it to stop the check without
     * flipping the switch).
     */
    public function manifestUrl(): ?string
    {
        $configured = $this->read(self::KEY_MANIFEST_URL);
        if (null === $configured) {
            return self::DEFAULT_MANIFEST_URL;
        }

        return $this->isUsableUrl($configured) ? $configured : null;
    }

    public function latestVersion(): ?string
    {
        return $this->read(self::KEY_LATEST_VERSION);
    }

    public function latestNotesUrl(): ?string
    {
        $stored = $this->read(self::KEY_LATEST_NOTES_URL);

        return null !== $stored && $this->isUsableUrl($stored) ? $stored : null;
    }

    /**
     * Stored severity, normalised to one of the two known values so an unknown
     * or missing value can never be rendered as a security warning.
     */
    public function latestSeverity(): string
    {
        return ReleaseManifest::SEVERITY_SECURITY === $this->read(self::KEY_LATEST_SEVERITY)
            ? ReleaseManifest::SEVERITY_SECURITY
            : ReleaseManifest::SEVERITY_NORMAL;
    }

    public function latestReleasedAt(): ?string
    {
        return $this->read(self::KEY_LATEST_RELEASED_AT);
    }

    public function lastCheckedAt(): ?string
    {
        return $this->read(self::KEY_LAST_CHECKED_AT);
    }

    public function lastError(): ?string
    {
        return $this->read(self::KEY_LAST_ERROR);
    }

    public function dismissedVersion(): ?string
    {
        return $this->read(self::KEY_DISMISSED_VERSION);
    }

    public function setCheckEnabled(bool $enabled): void
    {
        $this->write(self::KEY_CHECK_ENABLED, $enabled ? '1' : '0');
    }

    public function setDismissedVersion(string $version): void
    {
        $this->write(self::KEY_DISMISSED_VERSION, $version);
    }

    /**
     * Persist the outcome of a completed check.
     *
     * A null $recommended clears the result fields: that is how a manifest
     * whose stable release was withdrawn, or one that turned out to be
     * unusable, stops being offered. Success always clears LAST_ERROR.
     */
    public function recordSuccessfulCheck(?ReleaseManifest $recommended, string $checkedAt): void
    {
        $this->write(self::KEY_LATEST_VERSION, $recommended->version ?? '');
        $this->write(self::KEY_LATEST_NOTES_URL, $recommended->notesUrl ?? '');
        $this->write(self::KEY_LATEST_SEVERITY, $recommended->severity ?? '');
        $this->write(self::KEY_LATEST_RELEASED_AT, $recommended->releasedAt ?? '');
        $this->write(self::KEY_LAST_ERROR, '');
        $this->write(self::KEY_LAST_CHECKED_AT, $checkedAt);
    }

    /**
     * Record that a check ran but produced nothing usable. The previously known
     * release is deliberately KEPT so a transient outage does not hide a notice
     * that was already correct.
     */
    public function recordFailedCheck(string $error, string $checkedAt): void
    {
        $this->write(self::KEY_LAST_ERROR, mb_substr($error, 0, self::MAX_ERROR_LENGTH));
        $this->write(self::KEY_LAST_CHECKED_AT, $checkedAt);
    }

    /**
     * A missing row and a blank value are the same thing: "not configured".
     */
    private function read(string $setting): ?string
    {
        $raw = $this->configRepository->getValue(self::OWNER_ID, self::CONFIG_GROUP, $setting);
        if (null === $raw) {
            return null;
        }

        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    private function write(string $setting, string $value): void
    {
        $this->configRepository->setValue(self::OWNER_ID, self::CONFIG_GROUP, $setting, $value);
    }

    private function isUsableUrl(string $url): bool
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        return \in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
