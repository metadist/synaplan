<?php

declare(strict_types=1);

namespace App\Service\Update;

use Psr\Log\LoggerInterface;

/**
 * Detects — and only detects — that a newer release of Synaplan exists.
 *
 * This never changes the running installation: no download, no version
 * selection, no state file. {@see refresh()} is called once per day by the
 * scheduler container and stores its outcome in BCONFIG; {@see getStatus()}
 * reads those stored values only, so the admin API performs no outbound HTTP
 * and keeps working without internet access.
 *
 * Comparison rules:
 *   - PHP's built-in version_compare() (no extra dependency), which also orders
 *     pre-releases sanely ('4.1.0-rc.1' < '4.1.0').
 *   - An unparseable current version — 'dev' being the normal case for a
 *     source checkout — means "cannot compare": no update is reported rather
 *     than a wrong one.
 *   - A version listed as `yanked` in the manifest is never recommended: it is
 *     not even stored, so it cannot resurface from BCONFIG later.
 */
final readonly class UpdateStatusService
{
    /**
     * Mirrors {@see UpdateManifestClient} so a stored version is held to the
     * same shape as a fetched one.
     */
    private const VERSION_PATTERN = '/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.\-]+)?$/';

    public function __construct(
        private UpdateConfig $config,
        private UpdateManifestClient $manifestClient,
        private LoggerInterface $logger,
        private string $appVersion,
    ) {
    }

    /**
     * The stored update status. Pure read: no HTTP, no writes.
     *
     * @return array{currentVersion: string, latestVersion: string|null, updateAvailable: bool, notesUrl: string|null, severity: string, releasedAt: string|null, lastCheckedAt: string|null, lastError: string|null, dismissedVersion: string|null, checkEnabled: bool}
     */
    public function getStatus(): array
    {
        $currentVersion = $this->currentVersion();
        $latestVersion = $this->config->latestVersion();

        return [
            'currentVersion' => $currentVersion,
            'latestVersion' => $latestVersion,
            'updateAvailable' => $this->isNewer($currentVersion, $latestVersion),
            'notesUrl' => $this->config->latestNotesUrl(),
            'severity' => $this->config->latestSeverity(),
            'releasedAt' => $this->config->latestReleasedAt(),
            'lastCheckedAt' => $this->config->lastCheckedAt(),
            'lastError' => $this->config->lastError(),
            'dismissedVersion' => $this->config->dismissedVersion(),
            'checkEnabled' => $this->config->isCheckEnabled(),
        ];
    }

    /**
     * Run the check now and store its outcome, then return the fresh status.
     *
     * A disabled master switch (or an unusable manifest URL) short-circuits
     * BEFORE the client is consulted, so no outbound request is made at all.
     * A network or parsing failure is a normal outcome: it is recorded in
     * LAST_ERROR and never raised.
     *
     * @return array{currentVersion: string, latestVersion: string|null, updateAvailable: bool, notesUrl: string|null, severity: string, releasedAt: string|null, lastCheckedAt: string|null, lastError: string|null, dismissedVersion: string|null, checkEnabled: bool}
     */
    public function refresh(bool $force = false): array
    {
        if (!$this->config->isCheckEnabled()) {
            return $this->getStatus();
        }

        $manifestUrl = $this->config->manifestUrl();
        $checkedAt = $this->now();

        if (null === $manifestUrl) {
            $this->config->recordFailedCheck('No valid manifest URL is configured.', $checkedAt);

            return $this->getStatus();
        }

        $manifest = $this->manifestClient->fetch($manifestUrl, $force);

        if (null === $manifest) {
            $this->config->recordFailedCheck(
                sprintf('Could not read a usable release manifest from %s.', $manifestUrl),
                $checkedAt,
            );

            return $this->getStatus();
        }

        // A withdrawn stable release is not an error, it just must never be
        // recommended — so the stored result is cleared instead of updated.
        if ($manifest->isYanked($manifest->version)) {
            $this->logger->warning('Published stable release is listed as yanked; not offering it', [
                'manifestUrl' => $manifestUrl,
                'version' => $manifest->version,
            ]);
            $this->config->recordSuccessfulCheck(null, $checkedAt);

            return $this->getStatus();
        }

        $this->config->recordSuccessfulCheck($manifest, $checkedAt);

        return $this->getStatus();
    }

    /**
     * Remember that the admin acknowledged this version, so the UI can stop
     * nagging about it. Display state only — nothing else reads it.
     */
    public function dismiss(string $version): void
    {
        $this->config->setDismissedVersion(trim($version));
    }

    public function setCheckEnabled(bool $enabled): void
    {
        $this->config->setCheckEnabled($enabled);
    }

    /**
     * Whether a version string can take part in a comparison at all.
     */
    public function isComparableVersion(string $version): bool
    {
        return 1 === preg_match(self::VERSION_PATTERN, $version);
    }

    /**
     * Same source and same fallback as the runtime config's build info: the
     * release pipeline sets APP_VERSION, and a plain checkout reports 'dev'.
     */
    private function currentVersion(): string
    {
        $version = trim($this->appVersion);

        return '' === $version ? 'dev' : $version;
    }

    private function isNewer(string $currentVersion, ?string $latestVersion): bool
    {
        if (null === $latestVersion || !$this->isComparableVersion($latestVersion)) {
            return false;
        }

        if (!$this->isComparableVersion($currentVersion)) {
            return false;
        }

        return version_compare($latestVersion, $currentVersion, '>');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
