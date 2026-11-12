<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Health\FailureKind;
use App\AI\Service\ModelProbeResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Workers AI catalog.
 *
 * Account-scoped and therefore not covered by {@see PlatformKeyModelListProbe},
 * whose endpoints all come from {@see \App\AI\Credential\ProviderKeyCatalog}.
 * The search endpoint is free and returns the models this account may use.
 */
final readonly class CloudflareModelListProbe implements ModelListProbeInterface
{
    private const TIMEOUT_SECONDS = 12;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $accountId = null,
        private ?string $apiToken = null,
    ) {
    }

    public function supports(string $service): bool
    {
        return 'cloudflare' === mb_strtolower($service);
    }

    public function probe(string $service): ProbeResult
    {
        if (null === $this->accountId || '' === trim($this->accountId)
            || null === $this->apiToken || '' === trim($this->apiToken)) {
            return ProbeResult::skipped('CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN are not configured.');
        }

        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search',
            rawurlencode($this->accountId)
        );

        $page = $this->requestCatalog($url, (string) $this->apiToken);
        if ($page instanceof ProbeResult) {
            return $page;
        }

        $status = $page['status'];
        $body = $page['body'];
        if (429 === $status) {
            return ProbeResult::reachable('Cloudflare rate-limited the catalog check (HTTP 429); credentials accepted.');
        }
        if ($status < 200 || $status >= 300) {
            return ProbeResult::failed(
                match (true) {
                    401 === $status, 403 === $status => FailureKind::Credential,
                    $status >= 500 => FailureKind::Transient,
                    default => FailureKind::Permanent,
                },
                sprintf('Cloudflare catalog returned HTTP %d.', $status)
            );
        }

        $ids = [];
        $rows = is_array($body['result'] ?? null) ? $body['result'] : [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && '' !== $row['name']) {
                $ids[] = mb_strtolower($row['name']);
            }
        }

        if ([] === $ids) {
            return ProbeResult::reachable('Cloudflare answered but returned no model list.');
        }

        // The search endpoint spans every Workers AI task type, so absence from
        // it is a real verdict for any capability.
        return ProbeResult::ok($ids, listingAuthoritative: true);
    }

    /** Never reached: the search listing above is authoritative on its own. */
    public function confirm(string $service, string $providerModelId): ModelProbeResult
    {
        return ModelProbeResult::Inconclusive;
    }

    /**
     * @return array{status: int, body: array<mixed>}|ProbeResult
     */
    private function requestCatalog(string $url, string $token): array|ProbeResult
    {
        $lastError = 'the provider did not answer';
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);
                $status = $response->getStatusCode();
                if ((401 === $status || 403 === $status || 429 === $status || $status >= 500) && 0 === $attempt) {
                    $lastError = sprintf('HTTP %d', $status);
                    continue;
                }

                return [
                    'status' => $status,
                    'body' => $status >= 200 && $status < 300 ? $response->toArray(false) : [],
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if (0 === $attempt) {
                    continue;
                }
            }
        }

        $this->logger->info('Cloudflare catalog probe failed', ['error' => $lastError]);

        return ProbeResult::failed(FailureKind::Transient, 'Could not reach the Cloudflare API: '.$lastError);
    }
}
