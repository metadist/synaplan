<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Resolves Higgsfield API credentials with per-user override on top of a
 * platform-wide default.
 *
 * Precedence (highest first):
 *   1. Per-user encrypted BCONFIG row (group="higgsfield", setting="api_key"
 *      and "api_secret"). Per-user values are AES-256-CBC encrypted at rest
 *      via {@see EncryptionService} (which derives its key from APP_SECRET).
 *   2. Platform-wide env credentials (HIGGSFIELD_API_KEY / HIGGSFIELD_API_SECRET)
 *      injected at construction time.
 *
 * A null return value means no credentials are configured for the given user
 * AND no platform fallback is set — the provider should treat that as "not
 * available" rather than failing with a confusing API error.
 *
 * Per-user keys must come as a pair (both api_key AND api_secret). A half-set
 * pair is treated as not-configured for that user and falls through to the
 * platform default.
 */
final class HiggsfieldCredentialResolver
{
    public const CONFIG_GROUP = 'higgsfield';
    public const SETTING_API_KEY = 'api_key';
    public const SETTING_API_SECRET = 'api_secret';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
        private readonly string $platformApiKey = '',
        private readonly string $platformApiSecret = '',
    ) {
    }

    /**
     * Resolve credentials for the given user.
     *
     * @return array{api_key: string, api_secret: string, source: 'user'|'platform'}|null
     */
    public function resolve(?int $userId): ?array
    {
        if (null !== $userId && $userId > 0) {
            $userCreds = $this->loadUserCredentials($userId);
            if (null !== $userCreds) {
                return [
                    'api_key' => $userCreds['api_key'],
                    'api_secret' => $userCreds['api_secret'],
                    'source' => 'user',
                ];
            }
        }

        if ('' !== $this->platformApiKey && '' !== $this->platformApiSecret) {
            return [
                'api_key' => $this->platformApiKey,
                'api_secret' => $this->platformApiSecret,
                'source' => 'platform',
            ];
        }

        return null;
    }

    /**
     * Is there any usable credential at all (platform OR for this user)?
     */
    public function hasCredentials(?int $userId): bool
    {
        return null !== $this->resolve($userId);
    }

    /**
     * Is there a platform-wide credential configured (independent of user)?
     */
    public function hasPlatformCredentials(): bool
    {
        return '' !== $this->platformApiKey && '' !== $this->platformApiSecret;
    }

    /**
     * Does this user have their own per-user override stored?
     */
    public function hasUserCredentials(int $userId): bool
    {
        return null !== $this->loadUserCredentials($userId);
    }

    /**
     * Store (or replace) a per-user key+secret pair, encrypted at rest.
     */
    public function saveUserCredentials(int $userId, string $apiKey, string $apiSecret): void
    {
        $this->configRepository->setValue(
            $userId,
            self::CONFIG_GROUP,
            self::SETTING_API_KEY,
            $this->encryption->encrypt($apiKey),
        );
        $this->configRepository->setValue(
            $userId,
            self::CONFIG_GROUP,
            self::SETTING_API_SECRET,
            $this->encryption->encrypt($apiSecret),
        );
    }

    /**
     * Drop the per-user override so the user falls back to the platform key.
     */
    public function clearUserCredentials(int $userId): void
    {
        $this->configRepository->deleteValue($userId, self::CONFIG_GROUP, self::SETTING_API_KEY);
        $this->configRepository->deleteValue($userId, self::CONFIG_GROUP, self::SETTING_API_SECRET);
    }

    /**
     * Return a masked hint of the current per-user API key (never the secret).
     * Useful for "Key configured" UI affordances. Empty string when not set.
     */
    public function maskedUserApiKey(int $userId): string
    {
        $creds = $this->loadUserCredentials($userId);

        return null === $creds ? '' : $this->mask($creds['api_key']);
    }

    /**
     * @return array{api_key: string, api_secret: string}|null
     */
    private function loadUserCredentials(int $userId): ?array
    {
        $keyCipher = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::SETTING_API_KEY);
        $secretCipher = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::SETTING_API_SECRET);

        if (null === $keyCipher || '' === $keyCipher
            || null === $secretCipher || '' === $secretCipher) {
            return null;
        }

        try {
            $apiKey = $this->encryption->decrypt($keyCipher);
            $apiSecret = $this->encryption->decrypt($secretCipher);
        } catch (\Throwable $e) {
            // A decrypt failure most often means APP_SECRET changed since the
            // value was written. Treat it as "not configured" rather than
            // breaking the request — and log it so operators see the cause.
            $this->logger->error('Higgsfield: failed to decrypt per-user credentials, falling back to platform key', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ('' === $apiKey || '' === $apiSecret) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    /**
     * Mask a key for display: keep the first 4 characters, hide the rest.
     */
    private function mask(string $key): string
    {
        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 4).str_repeat('*', max(4, strlen($key) - 4));
    }
}
