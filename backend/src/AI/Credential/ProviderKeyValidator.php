<?php

declare(strict_types=1);

namespace App\AI\Credential;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Live validation of a cloud provider API key: one cheap authenticated
 * request (list models / whoami) against the provider's API.
 *
 * Used by the admin provider-key endpoints before persisting a key and by the
 * explicit "Test" action. Deliberately NOT used for the env → DB import in
 * {@see ProviderKeyStore} — a network blip at boot must never drop a key.
 */
final readonly class ProviderKeyValidator
{
    private const TIMEOUT_SECONDS = 12;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ok: bool, status?: int, error?: string}
     */
    public function validate(string $provider, string $key): array
    {
        $provider = strtolower(trim($provider));
        $key = trim($key);
        if ('' === $key) {
            return ['ok' => false, 'error' => 'API key must not be empty.'];
        }
        if (!ProviderKeyCatalog::has($provider)) {
            return ['ok' => false, 'error' => sprintf('Unknown provider "%s".', $provider)];
        }

        $check = ProviderKeyCatalog::get($provider)['validation'];
        $headers = [];
        foreach ($check['headers'] as $name => $value) {
            $headers[$name] = str_replace('{key}', $key, $value);
        }

        try {
            $response = $this->httpClient->request($check['method'], $check['url'], [
                'headers' => $headers,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Provider key validation request failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'Could not reach the provider API: '.$e->getMessage()];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status];
        }

        $error = match (true) {
            401 === $status, 403 === $status => 'The provider rejected this API key.',
            429 === $status => 'The provider rate-limited the validation request; the key format was accepted.',
            default => sprintf('The provider API returned HTTP %d.', $status),
        };

        // 429 means the key authenticated far enough to be rate-limited —
        // treat it as valid rather than blocking the save.
        return ['ok' => 429 === $status, 'status' => $status, 'error' => $error];
    }
}
