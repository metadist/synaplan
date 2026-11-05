<?php

declare(strict_types=1);

namespace App\Service\Media;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the signer sidecar for a C2PA manifest. The private key stays on the sidecar.
 * A missing or failing sidecar returns null and the caller keeps the original bytes.
 */
final readonly class C2paSignerClient
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $token = '',
    ) {
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);
        $token = trim($this->token);

        return '' !== $url && 'disabled' !== $url && '' !== $token;
    }

    public function sign(string $bytes, string $filename, string $modelLabel, bool $hasParent): ?string
    {
        if (!$this->isConfigured() || '' === $bytes) {
            return null;
        }

        $headers = [];
        $token = trim($this->token);
        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/sign', [
                'timeout' => 5,
                'headers' => $headers,
                'json' => [
                    'bytes' => base64_encode($bytes),
                    'filename' => $filename,
                    'model' => $modelLabel,
                    'parent' => $hasParent,
                ],
            ]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $encoded = $payload['bytes'] ?? null;
        if (!is_string($encoded) || '' === $encoded) {
            return null;
        }
        $signed = base64_decode($encoded, true);

        return is_string($signed) && '' !== $signed ? $signed : null;
    }
}
