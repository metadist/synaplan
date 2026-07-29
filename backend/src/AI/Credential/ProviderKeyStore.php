<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Install-wide store for cloud AI provider API keys (Groq, OpenAI, Anthropic,
 * Gemini, Mistral, TrustedTokens, HuggingFace, xAI).
 *
 * Keys live in BCONFIG (ownerId = 0, group {@see self::CONFIG_GROUP}, one row
 * per provider) as an AES-256-CBC encrypted JSON payload
 * `{"key": "...", "origin": "env"|"ui"}` via {@see EncryptionService} — the
 * same at-rest pattern as OpenAiCompatibleEndpointRegistry and the Higgsfield
 * credentials. Keys therefore NEVER appear in migrations, seeders, fixtures,
 * or any file tracked by git; they enter the database exclusively at runtime
 * inside the operator's own installation.
 *
 * Resolution order for {@see self::getKey()}:
 *   1. A stored DB row wins. A row saved through the admin UI/wizard
 *      (origin "ui") permanently wins over the environment, so operators can
 *      remove the key from `.env` after it has been transferred.
 *   2. Env bootstrap ("transfer on first load"): when no DB row exists and
 *      the provider's env var is set, the env key is imported into the DB
 *      (origin "env") and used. When a row with origin "env" exists and the
 *      env var was CHANGED to a different non-empty value, the DB copy is
 *      refreshed — key rotation via `.env`/orchestrator secrets keeps working.
 *   3. Neither configured → null (provider reports itself unavailable).
 *
 * The import deliberately checks presence, not live validity: a network blip
 * at boot must never drop a valid key. Live validation happens in the admin
 * endpoints via {@see ProviderKeyValidator}.
 *
 * Reads are memoized for a few seconds per process so per-request provider
 * calls don't hammer BCONFIG, while long-lived processes (FrankenPHP worker
 * mode, the messenger worker container) still pick up UI key changes quickly
 * without a restart.
 */
final class ProviderKeyStore
{
    public const CONFIG_GROUP = 'provider_keys';

    public const ORIGIN_ENV = 'env';
    public const ORIGIN_UI = 'ui';

    /** Providers whose platform API key may live in this store. */
    public const SUPPORTED_PROVIDERS = [
        'anthropic',
        'openai',
        'groq',
        'google',
        'mistral',
        'trustedtokens',
        'huggingface',
        'xai',
    ];

    private const MEMO_TTL_SECONDS = 15;

    /** @var array<string, array{key: ?string, at: int}> */
    private array $memo = [];

    /**
     * @param array<string, string> $envKeys provider name => key from the
     *                                       environment ('' when unset);
     *                                       wired in services.yaml
     */
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
        private readonly array $envKeys = [],
    ) {
    }

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * Resolve the current API key for a provider (DB first, env bootstrap).
     * Returns null when the provider is not configured at all.
     */
    public function getKey(string $provider): ?string
    {
        $provider = strtolower(trim($provider));
        if (!self::isSupported($provider)) {
            return null;
        }

        $memo = $this->memo[$provider] ?? null;
        if (null !== $memo && time() - $memo['at'] < self::MEMO_TTL_SECONDS) {
            return $memo['key'];
        }

        $key = $this->resolveKey($provider);
        $this->memo[$provider] = ['key' => $key, 'at' => time()];

        return $key;
    }

    /**
     * Store (or replace) a provider key, encrypted at rest.
     */
    public function saveKey(string $provider, string $key, string $origin = self::ORIGIN_UI): void
    {
        $provider = strtolower(trim($provider));
        if (!self::isSupported($provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown AI provider "%s". Supported: %s.', $provider, implode(', ', self::SUPPORTED_PROVIDERS)));
        }

        $key = trim($key);
        if ('' === $key) {
            throw new \InvalidArgumentException('API key must not be empty. Use deleteKey() to remove a stored key.');
        }

        $payload = json_encode(['key' => $key, 'origin' => $origin], JSON_THROW_ON_ERROR);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, $provider, $this->encryption->encrypt($payload));
        unset($this->memo[$provider]);

        // Never log the key itself — only that one was stored.
        $this->logger->info('AI provider key saved', [
            'provider' => $provider,
            'origin' => $origin,
        ]);
    }

    /**
     * Remove the stored key. The provider falls back to the env var (if any)
     * on the next resolution — which will re-import it.
     */
    public function deleteKey(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        unset($this->memo[$provider]);

        $deleted = $this->configRepository->deleteValue(0, self::CONFIG_GROUP, $provider);
        if ($deleted) {
            $this->logger->info('AI provider key deleted', ['provider' => $provider]);
        }

        return $deleted;
    }

    /**
     * Configuration status for the admin UI. Never exposes the key itself.
     *
     * @return array{configured: bool, source: 'db'|'env'|'none', origin: ?string, maskedKey: string}
     */
    public function getStatus(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $row = $this->loadRow($provider);
        if (null !== $row) {
            return [
                'configured' => true,
                'source' => 'db',
                'origin' => $row['origin'],
                'maskedKey' => self::mask($row['key']),
            ];
        }

        $envKey = trim($this->envKeys[$provider] ?? '');
        if ('' !== $envKey) {
            return [
                'configured' => true,
                'source' => 'env',
                'origin' => null,
                'maskedKey' => self::mask($envKey),
            ];
        }

        return ['configured' => false, 'source' => 'none', 'origin' => null, 'maskedKey' => ''];
    }

    /**
     * Mask a key for display: keep a short recognizable prefix + suffix,
     * hide everything else.
     */
    public static function mask(string $key): string
    {
        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($key, 0, 4).str_repeat('•', min(12, $length - 8)).substr($key, -4);
    }

    private function resolveKey(string $provider): ?string
    {
        $envKey = trim($this->envKeys[$provider] ?? '');
        $row = $this->loadRow($provider);

        if (null === $row) {
            if ('' === $envKey) {
                return null;
            }

            // One-time env → DB transfer. Failure (e.g. table missing during
            // an early boot phase) must never break the request: fall back to
            // the env key and let a later resolution retry the import.
            try {
                $this->saveKey($provider, $envKey, self::ORIGIN_ENV);
            } catch (\Throwable $e) {
                $this->logger->warning('AI provider key env import failed, using env key directly', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }

            return $envKey;
        }

        // Env rotation: a row imported from env follows the env var when the
        // operator ships a new non-empty value. A UI-saved key never does.
        if (self::ORIGIN_ENV === $row['origin'] && '' !== $envKey && $envKey !== $row['key']) {
            try {
                $this->saveKey($provider, $envKey, self::ORIGIN_ENV);
            } catch (\Throwable $e) {
                $this->logger->warning('AI provider key env rotation update failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }

            return $envKey;
        }

        return '' !== $row['key'] ? $row['key'] : null;
    }

    /**
     * @return array{key: string, origin: string}|null
     */
    private function loadRow(string $provider): ?array
    {
        $cipher = $this->configRepository->getValue(0, self::CONFIG_GROUP, $provider);
        if (null === $cipher || '' === $cipher) {
            return null;
        }

        try {
            $decoded = json_decode($this->encryption->decrypt($cipher), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // Most likely APP_SECRET changed since the row was written. Treat
            // as not-configured (env fallback still applies) and log the cause.
            $this->logger->error('AI provider key decrypt failed, treating as not configured', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!is_array($decoded) || !is_string($decoded['key'] ?? null)) {
            return null;
        }

        $origin = $decoded['origin'] ?? self::ORIGIN_UI;

        return [
            'key' => $decoded['key'],
            'origin' => is_string($origin) ? $origin : self::ORIGIN_UI,
        ];
    }
}
