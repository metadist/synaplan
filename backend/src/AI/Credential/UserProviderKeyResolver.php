<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Resolves a cloud-provider API key with per-user BYO override on top of the
 * install-wide operator key from {@see ProviderKeyStore}.
 *
 * Precedence (highest first):
 *   1. Per-user encrypted BCONFIG row (group=provider_keys_user, setting=$provider)
 *   2. Operator key via ProviderKeyStore (when $allowOperatorKey is true)
 *
 * Generalized from {@see HiggsfieldCredentialResolver} for anthropic / openai /
 * google (and any future provider name).
 */
final class UserProviderKeyResolver
{
    public const CONFIG_GROUP = 'provider_keys_user';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EncryptionService $encryption,
        private readonly ProviderKeyStore $providerKeyStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{key: string, source: 'user'|'operator'}|null
     */
    public function resolve(string $provider, ?int $userId, bool $allowOperatorKey = false): ?array
    {
        $provider = strtolower(trim($provider));
        if ('' === $provider) {
            return null;
        }

        if (null !== $userId && $userId > 0) {
            $userKey = $this->loadUserKey($userId, $provider);
            if (null !== $userKey) {
                return ['key' => $userKey, 'source' => 'user'];
            }
        }

        if ($allowOperatorKey) {
            $operatorKey = $this->providerKeyStore->getKey($provider);
            if (null !== $operatorKey && '' !== $operatorKey) {
                return ['key' => $operatorKey, 'source' => 'operator'];
            }
        }

        return null;
    }

    public function hasUserKey(int $userId, string $provider): bool
    {
        return null !== $this->loadUserKey($userId, strtolower(trim($provider)));
    }

    public function saveUserKey(int $userId, string $provider, string $apiKey): void
    {
        $provider = strtolower(trim($provider));
        $this->configRepository->setValue(
            $userId,
            self::CONFIG_GROUP,
            $provider,
            $this->encryption->encrypt($apiKey),
        );
    }

    public function clearUserKey(int $userId, string $provider): void
    {
        $this->configRepository->deleteValue($userId, self::CONFIG_GROUP, strtolower(trim($provider)));
    }

    /**
     * Masked hint for UI display. Empty string when not set.
     */
    public function maskedUserKey(int $userId, string $provider): string
    {
        $key = $this->loadUserKey($userId, strtolower(trim($provider)));
        if (null === $key) {
            return '';
        }

        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 4).str_repeat('*', max(4, strlen($key) - 4));
    }

    private function loadUserKey(int $userId, string $provider): ?string
    {
        $cipher = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $provider);
        if (null === $cipher || '' === $cipher) {
            return null;
        }

        try {
            $key = $this->encryption->decrypt($cipher);
        } catch (\Throwable $e) {
            $this->logger->error('UserProviderKeyResolver: decrypt failed, treating as unset', [
                'user_id' => $userId,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return '' !== $key ? $key : null;
    }
}
