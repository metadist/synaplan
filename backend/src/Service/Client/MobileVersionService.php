<?php

declare(strict_types=1);

namespace App\Service\Client;

use App\Repository\ConfigRepository;

/**
 * Forced-update gate (Epic 8.2).
 *
 * Reads the operator-configured minimum supported app version (and the store
 * links for the "please update" screen) from the MOBILE BCONFIG group and
 * decides whether a given mobile client is too old to run.
 *
 * The version compared here is the server-parsed value from the frozen
 * "Synaplan Mobile Vx.x" User-Agent token (Epic 2), so the gate uses the same
 * authoritative source as analytics/branding — never a client-supplied claim.
 *
 * Empty / unset min-version means "no gate" (default), so a fresh or
 * open-source deployment never blocks anyone.
 */
final readonly class MobileVersionService
{
    public const GROUP = 'MOBILE';
    public const OWNER_ID = 0;

    public const KEY_MIN_APP_VERSION = 'MIN_APP_VERSION';
    public const KEY_IOS_APP_URL = 'IOS_APP_URL';
    public const KEY_ANDROID_APP_URL = 'ANDROID_APP_URL';

    public const DEFAULT_MIN_APP_VERSION = '';
    public const DEFAULT_IOS_APP_URL = '';
    public const DEFAULT_ANDROID_APP_URL = '';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * The configured minimum supported app version, or '' when no gate is set.
     */
    public function getMinVersion(): string
    {
        $raw = $this->configRepository->getValue(self::OWNER_ID, self::GROUP, self::KEY_MIN_APP_VERSION);

        return null === $raw ? self::DEFAULT_MIN_APP_VERSION : trim($raw);
    }

    /**
     * @return array{ios: string, android: string}
     */
    public function getStoreUrls(): array
    {
        return [
            'ios' => $this->value(self::KEY_IOS_APP_URL, self::DEFAULT_IOS_APP_URL),
            'android' => $this->value(self::KEY_ANDROID_APP_URL, self::DEFAULT_ANDROID_APP_URL),
        ];
    }

    /**
     * True when the client is the mobile app, a minimum version is configured,
     * and the app's version is strictly below it.
     */
    public function isUpdateRequired(ClientContext $client): bool
    {
        if (!$client->isMobileApp || null === $client->appVersion) {
            return false;
        }

        $min = $this->getMinVersion();
        if ('' === $min) {
            return false;
        }

        return version_compare($client->appVersion, $min, '<');
    }

    private function value(string $setting, string $default): string
    {
        $raw = $this->configRepository->getValue(self::OWNER_ID, self::GROUP, $setting);
        if (null === $raw) {
            return $default;
        }

        // Trim and treat whitespace-only as unset, so an operator pasting a store
        // URL with stray surrounding whitespace never produces an invalid href.
        $trimmed = trim($raw);

        return '' === $trimmed ? $default : $trimmed;
    }
}
