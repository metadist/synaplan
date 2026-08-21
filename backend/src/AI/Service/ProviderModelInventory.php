<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\Model\ModelCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks a cloud provider which models it currently serves.
 *
 * Reuses the listing endpoints already declared in {@see ProviderKeyCatalog}
 * for API-key validation — those requests hit `/models` (or the provider's
 * equivalent) and throw the response body away. This service keeps the body.
 *
 * Two provider shapes cover all supported APIs: OpenAI-compatible payloads
 * expose `data[].id`, Google exposes `models[].name` prefixed with `models/`.
 * Both are read on every response rather than switched per provider, so a
 * provider that changes shape degrades to "unreachable" instead of silently
 * reporting an empty catalog.
 */
final readonly class ProviderModelInventory implements ProviderModelInventoryInterface
{
    private const TIMEOUT_SECONDS = 20;
    private const PROBE_TIMEOUT_SECONDS = 10;

    /**
     * Providers whose key-validation endpoint is not a model listing.
     * HuggingFace validates against `whoami-v2`, which says nothing about
     * served models, so it can never contribute an availability verdict.
     *
     * @var list<string>
     */
    private const NO_LISTING_ENDPOINT = ['huggingface'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderKeyStore $keyStore,
        private LoggerInterface $logger,
    ) {
    }

    public function fetch(string $provider): ProviderModelListing
    {
        $provider = ModelCatalog::normalizeProvider($provider);

        if (!ProviderKeyCatalog::has($provider)) {
            return ProviderModelListing::noListingEndpoint('Provider is not key-managed (self-hosted or per-install endpoint).');
        }

        if (in_array($provider, self::NO_LISTING_ENDPOINT, true)) {
            return ProviderModelListing::noListingEndpoint('Provider exposes no model-listing endpoint.');
        }

        $key = $this->keyStore->getKey($provider);
        if (null === $key) {
            return ProviderModelListing::notConfigured();
        }

        $listing = ProviderKeyCatalog::get($provider)['validation'];
        $headers = [];
        foreach ($listing['headers'] as $name => $value) {
            $headers[$name] = str_replace('{key}', $key, $value);
        }

        try {
            $response = $this->httpClient->request($listing['method'], $listing['url'], [
                'headers' => $headers,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return ProviderModelListing::unreachable(sprintf('The provider API returned HTTP %d.', $status));
            }

            $modelIds = $this->extractModelIds($response->toArray(false));
        } catch (\Throwable $e) {
            $this->logger->warning('Model availability listing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ProviderModelListing::unreachable('Could not read the provider model list: '.$e->getMessage());
        }

        if ([] === $modelIds) {
            return ProviderModelListing::unreachable('The provider returned no recognisable model list.');
        }

        return ProviderModelListing::ok($modelIds);
    }

    /**
     * Ask the provider about one specific model id.
     *
     * This is the reliable oracle, and the bulk listing is only a cheap
     * pre-filter for it. A listing can omit a model that is very much alive:
     * Gemini serves `imagen-4.0-generate-001` through `:predict` and leaves it
     * out of `models.list`, while `GET /v1beta/models/imagen-4.0-generate-001`
     * answers 200. Judging by listing membership alone reports such models as
     * discontinued, and hides the opposite case just as easily.
     *
     * Every supported provider addresses a single model as `{listUrl}/{id}`.
     */
    public function probe(string $provider, string $providerModelId): ModelProbeResult
    {
        $provider = ModelCatalog::normalizeProvider($provider);
        if (!ProviderKeyCatalog::has($provider)) {
            return ModelProbeResult::Inconclusive;
        }

        $key = $this->keyStore->getKey($provider);
        if (null === $key) {
            return ModelProbeResult::Inconclusive;
        }

        $listing = ProviderKeyCatalog::get($provider)['validation'];
        $headers = [];
        foreach ($listing['headers'] as $name => $value) {
            $headers[$name] = str_replace('{key}', $key, $value);
        }

        try {
            $status = $this->httpClient->request('GET', rtrim($listing['url'], '/').'/'.ltrim($providerModelId, '/'), [
                'headers' => $headers,
                'timeout' => self::PROBE_TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Model availability probe failed', [
                'provider' => $provider,
                'model' => $providerModelId,
                'error' => $e->getMessage(),
            ]);

            return ModelProbeResult::Inconclusive;
        }

        // 400 is included because OpenAI-compatible providers answer an unknown
        // model with "model_not_found" under either status. 401/403/429/5xx say
        // nothing about the model and must stay inconclusive.
        return match (true) {
            $status >= 200 && $status < 300 => ModelProbeResult::Alive,
            404 === $status, 400 === $status => ModelProbeResult::Gone,
            default => ModelProbeResult::Inconclusive,
        };
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private function extractModelIds(array $payload): array
    {
        $ids = [];

        foreach ($this->entries($payload, 'data') as $entry) {
            $id = $entry['id'] ?? null;
            if (is_string($id) && '' !== $id) {
                $ids[] = $id;
            }
        }

        foreach ($this->entries($payload, 'models') as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && '' !== $name) {
                $ids[] = preg_replace('#^models/#', '', $name) ?? $name;
            }
        }

        return $ids;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array<mixed>>
     */
    private function entries(array $payload, string $key): array
    {
        $list = $payload[$key] ?? null;
        if (!is_array($list)) {
            return [];
        }

        $entries = [];
        foreach ($list as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
