<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Health\FailureKind;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderModelInventoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Catalog lookup for every cloud provider whose key lives in the
 * {@see ProviderKeyStore}: Groq, OpenAI, Anthropic, Google, Mistral,
 * TrustedTokens, HuggingFace and xAI.
 *
 * It reuses the endpoints {@see ProviderKeyCatalog} already declares for key
 * validation — all of them are free "list your models" calls, which is exactly
 * what outage detection needs. One class instead of one per provider: the
 * providers differ only in the JSON key their list hides behind, and eight
 * near-identical classes would drift apart the first time one of them changed.
 */
final readonly class PlatformKeyModelListProbe implements ModelListProbeInterface
{
    private const TIMEOUT_SECONDS = 12;

    /** Safety stop for paginated catalogs, so a broken cursor cannot loop forever. */
    private const MAX_PAGES = 10;

    /**
     * Providers whose validation endpoint confirms the credentials but does not
     * enumerate models. HuggingFace's whoami-v2 answers "this token is valid"
     * and nothing else — good enough to tell a key problem from an outage, not
     * good enough to declare a model retired.
     */
    private const NO_LISTING = ['huggingface'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderKeyStore $keyStore,
        private ProviderModelInventoryInterface $inventory,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $service): bool
    {
        return ProviderKeyCatalog::has(mb_strtolower($service));
    }

    public function probe(string $service): ProbeResult
    {
        $provider = mb_strtolower($service);
        if (!ProviderKeyCatalog::has($provider)) {
            return ProbeResult::skipped(sprintf('No catalog endpoint known for "%s".', $service));
        }

        $key = $this->keyStore->getKey($provider);
        if (null === $key || '' === $key) {
            // Not an outage: this install simply never configured the provider.
            return ProbeResult::skipped('No API key configured for this provider.');
        }

        $check = ProviderKeyCatalog::get($provider)['validation'];
        $headers = [];
        foreach ($check['headers'] as $name => $value) {
            $headers[$name] = str_replace('{key}', $key, $value);
        }

        $modelIds = [];
        $url = $check['url'];
        $pagesLeft = self::MAX_PAGES;

        // Google's model list is paginated and defaults to a page size well
        // below its catalog, so a single request silently omits whole model
        // families — and an omitted model looks exactly like a retired one.
        do {
            try {
                $response = $this->httpClient->request($check['method'], $url, [
                    'headers' => $headers,
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);
                $status = $response->getStatusCode();
                $body = $status >= 200 && $status < 300 ? $response->toArray(false) : [];
            } catch (\Throwable $e) {
                $this->logger->info('Model catalog probe could not reach provider', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);

                return ProbeResult::failed(FailureKind::Transient, 'Could not reach the provider API: '.$e->getMessage());
            }

            if ($status < 200 || $status >= 300) {
                return ProbeResult::failed(self::kindForStatus($status), sprintf('Provider catalog returned HTTP %d.', $status));
            }

            if (in_array($provider, self::NO_LISTING, true)) {
                return ProbeResult::reachable('Credentials accepted; this provider does not publish a model list.');
            }

            $modelIds = array_merge($modelIds, self::extractModelIds($body));

            $nextPage = is_string($body['nextPageToken'] ?? null) && '' !== $body['nextPageToken']
                ? $body['nextPageToken']
                : null;
            $url = null === $nextPage
                ? null
                : $check['url'].(str_contains($check['url'], '?') ? '&' : '?').'pageToken='.rawurlencode($nextPage);
        } while (null !== $url && --$pagesLeft > 0);

        $modelIds = array_values(array_unique($modelIds));

        if ([] === $modelIds) {
            // A 200 with an unparseable body means the provider is up but we
            // learned nothing. Reporting every model as retired on that basis
            // would be a catastrophic false positive.
            $this->logger->warning('Model catalog probe returned no recognisable model list', [
                'provider' => $provider,
            ]);

            return ProbeResult::reachable('Provider answered but returned no recognisable model list.');
        }

        // Never authoritative: these catalogs are partial. xAI's /v1/models
        // lists chat models only, and Google omits Imagen from models.list
        // while still serving it. Absence here is a hint, and confirm() decides.
        return ProbeResult::ok($modelIds, listingAuthoritative: false);
    }

    /**
     * Delegated to {@see ProviderModelInventoryInterface}, which the
     * availability check already uses for exactly this question. Keeping a
     * second implementation would mean two places encoding which status codes a
     * given provider returns for an unknown model — and the day one of them
     * learns that a provider answers 400 instead of 404, the other would keep
     * reporting that model as alive.
     */
    public function confirm(string $service, string $providerModelId): ModelProbeResult
    {
        $provider = mb_strtolower($service);
        if ('' === trim($providerModelId) || in_array($provider, self::NO_LISTING, true)) {
            return ModelProbeResult::Inconclusive;
        }

        return $this->inventory->probe($provider, $providerModelId);
    }

    /**
     * A rate-limited probe proves the credentials were accepted far enough to
     * be throttled. Treating it as a failure would report a healthy provider as
     * down every time the catalog endpoint is busy.
     */
    private static function kindForStatus(int $status): FailureKind
    {
        return match (true) {
            401 === $status, 403 === $status, 402 === $status => FailureKind::Credential,
            429 === $status => FailureKind::Transient,
            $status >= 500 => FailureKind::Transient,
            default => FailureKind::Permanent,
        };
    }

    /**
     * Pull model ids out of the two shapes these providers use: OpenAI-style
     * `{"data":[{"id":...}]}` (Groq, OpenAI, Anthropic, Mistral, xAI,
     * TrustedTokens) and Google's `{"models":[{"name":"models/..."}]}`.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function extractModelIds(array $body): array
    {
        $rows = [];
        foreach (['data', 'models', 'result'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $rows = $body[$key];
                break;
            }
        }

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $ids[] = mb_strtolower($row);
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            foreach (['id', 'name', 'model'] as $field) {
                if (isset($row[$field]) && is_string($row[$field]) && '' !== $row[$field]) {
                    $ids[] = mb_strtolower($row[$field]);
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
