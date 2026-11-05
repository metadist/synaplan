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
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->baseUrl && 'disabled' !== $this->baseUrl;
    }

    public function sign(string $bytes, string $filename, string $modelLabel, bool $hasParent): ?string
    {
        if (!$this->isConfigured() || '' === $bytes) {
            return null;
        }

        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/sign', [
                'timeout' => 20,
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
