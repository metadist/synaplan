<?php

declare(strict_types=1);

namespace App\Plug;

use App\AI\Credential\SecretValueGuard;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Encrypted store for plug API keys (Tavily, Exa, Firecrawl, Jina, Cohere, Voyage).
 *
 * Same at-rest shape as {@see \App\AI\Credential\ProviderKeyStore}: BCONFIG
 * group {@see self::CONFIG_GROUP}, AES-256-CBC JSON `{"key","origin"}`.
 * Brave stays on `BRAVE_SEARCH_API_KEY`; Perplexity shares
 * {@see \App\AI\Credential\ProviderKeyStore} with the chat provider.
 */
final class PlugKeyStore
{
    public const CONFIG_GROUP = 'plug_keys';

    public const ORIGIN_ENV = 'env';
    public const ORIGIN_UI = 'ui';

    /** @var list<string> */
    public const SUPPORTED_PROVIDERS = [
        'tavily',
        'exa',
        'firecrawl',
        'jina',
        'cohere',
        'voyage',
    ];

    private const MEMO_TTL_SECONDS = 15;

    /** @var array<string, array{key: ?string, at: int}> */
    private array $memo = [];

    /**
     * @param array<string, string|null> $envKeys        provider => env bootstrap value
     * @param list<string>               $pluginProviders keys declared by plugin provides.plugs adapters
     */
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
        private readonly array $envKeys = [],
        private readonly array $pluginProviders = [],
    ) {
    }

    /** Built-in plug providers only. */
    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * Built-in providers plus any key a discovered plugin declared — so a
     * plugin adapter can store and read its secret through the same store.
     */
    public function supports(string $provider): bool
    {
        $provider = strtolower(trim($provider));

        return self::isSupported($provider) || in_array($provider, $this->pluginProviders, true);
    }

    public function getKey(string $provider): ?string
    {
        $provider = strtolower(trim($provider));
        if (!$this->supports($provider)) {
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

    public function saveKey(string $provider, string $key, string $origin = self::ORIGIN_UI): void
    {
        $provider = strtolower(trim($provider));
        if (!$this->supports($provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown plug key provider "%s". Supported: %s.', $provider, implode(', ', [...self::SUPPORTED_PROVIDERS, ...$this->pluginProviders])));
        }

        $key = trim($key);
        if ('' === $key) {
            throw new \InvalidArgumentException('API key must not be empty. Use deleteKey() to remove a stored key.');
        }
        if (SecretValueGuard::isMasked($key)) {
            throw new \InvalidArgumentException('That is the masked display value, not an API key. Leave the field untouched to keep the stored key, or paste a new one.');
        }
        if (SecretValueGuard::isPlaceholder($key)) {
            throw new \InvalidArgumentException(sprintf('"%s" is a placeholder, not an API key. Paste the real key from the provider console.', $key));
        }

        $payload = json_encode(['key' => $key, 'origin' => $origin], JSON_THROW_ON_ERROR);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, $provider, $this->encryption->encrypt($payload));
        unset($this->memo[$provider]);

        $this->logger->info('Plug API key saved', [
            'provider' => $provider,
            'origin' => $origin,
        ]);
    }

    public function deleteKey(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        unset($this->memo[$provider]);

        $deleted = $this->configRepository->deleteValue(0, self::CONFIG_GROUP, $provider);
        if ($deleted) {
            $this->logger->info('Plug API key deleted', ['provider' => $provider]);
        }

        return $deleted;
    }

    /**
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

        $envKey = $this->envKey($provider);
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

    public function hasEnvKey(string $provider): bool
    {
        return '' !== $this->envKey(strtolower(trim($provider)));
    }

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
        $envKey = $this->envKey($provider);
        $row = $this->loadRow($provider);

        if (null === $row) {
            if ('' === $envKey) {
                return null;
            }

            try {
                $this->saveKey($provider, $envKey, self::ORIGIN_ENV);
            } catch (\Throwable $e) {
                $this->logger->warning('Plug API key env import failed, using env key directly', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }

            return $envKey;
        }

        if (self::ORIGIN_ENV === $row['origin'] && '' !== $envKey && $envKey !== $row['key']) {
            try {
                $this->saveKey($provider, $envKey, self::ORIGIN_ENV);
            } catch (\Throwable $e) {
                $this->logger->warning('Plug API key env rotation update failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }

            return $envKey;
        }

        return '' !== $row['key'] ? $row['key'] : null;
    }

    private function envKey(string $provider): string
    {
        $candidate = trim((string) ($this->envKeys[$provider] ?? ''));
        if (SecretValueGuard::isUsable($candidate)) {
            return $candidate;
        }
        if ('' !== $candidate) {
            $this->logger->warning('Ignoring placeholder value in plug key environment variable', [
                'provider' => $provider,
            ]);
        }

        return '';
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
            $this->logger->error('Plug API key decrypt failed, treating as not configured', [
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
