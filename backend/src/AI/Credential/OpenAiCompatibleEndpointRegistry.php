<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\AI\Import\ListedModelPrice;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Install-wide registry of "OpenAI Compatible" upstream endpoints (LocalAI,
 * vLLM, LiteLLM, Ollama's /v1, any gateway that speaks the OpenAI Chat
 * Completions, Embeddings, or Images API).
 *
 * An endpoint is a named tuple {base_url, api_key, headers, label,
 * capabilities}. `text2pic` means the endpoint speaks
 * `POST /images/generations` (LocalAI and other OpenAI-compatible image
 * servers). It is stored globally (ownerId = 0) in BCONFIG under the
 * group {@see self::CONFIG_GROUP}, one row per endpoint (setting =
 * "endpoint.<name>"), with the whole JSON payload encrypted at rest via
 * {@see EncryptionService} (which derives its key from APP_SECRET) — the same
 * pattern used for per-user Higgsfield credentials.
 *
 * A BMODELS row of service {@see self::SERVICE} references its endpoint by
 * name in BJSON ("endpoint": "<name>"); the model's providerId stays the
 * upstream model id sent in the request body. This lets one Synaplan install
 * expose several models across several self-hosted gateways.
 *
 * Every base URL an administrator saves or probes passes {@see SsrfGuard}
 * (GHSA-26f3-684h-hpjh, part b): http(s) only, no redirects, and the probe
 * connects to the address the guard resolved so a hostname cannot point
 * somewhere else between the check and the request. Because self-hosted
 * gateways live on the operator's own network, RFC 1918, loopback, ULA and
 * overlay-VPN ranges are accepted by default; link-local (cloud metadata)
 * and the other reserved ranges never are. {@see self::ENV_PUBLIC_ONLY}
 * switches to the public-only policy every user-supplied URL gets.
 */
final class OpenAiCompatibleEndpointRegistry
{
    /** BSERVICE value stored on BMODELS rows served by this provider. */
    public const SERVICE = 'OpenAICompatible';

    /** Registry key {@see \App\AI\Service\ProviderRegistry} uses (lowercased service). */
    public const PROVIDER_NAME = 'openaicompatible';

    public const CONFIG_GROUP = 'openai_compatible';

    /** Env flag: refuse upstreams on private networks (hosted / multi-tenant installs). */
    public const ENV_PUBLIC_ONLY = 'OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY';

    private const SETTING_PREFIX = 'endpoint.';

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Capabilities an endpoint may advertise (maps to BMODELS.BTAG values). */
    public const CAPABILITIES = ['chat', 'vectorize', 'pic2text', 'text2pic', 'rerank'];

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ModelRepository $modelRepository,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly SsrfGuard $ssrfGuard,
        #[Autowire(env: 'default::bool:'.self::ENV_PUBLIC_ONLY)]
        private readonly bool $publicUpstreamsOnly = false,
    ) {
    }

    /**
     * Probe an endpoint's connectivity by listing its models (GET /models).
     * Accepts either a stored endpoint $name OR explicit connection details
     * (for testing before saving). A null $apiKey with a known $name reuses the
     * stored key.
     *
     * @param array<string, string> $headers
     *
     * @return array{ok: bool, status?: int, model_count?: int, sample?: string[], error?: string}
     */
    public function testConnection(
        ?string $name = null,
        ?string $baseUrl = null,
        ?string $apiKey = null,
        array $headers = [],
    ): array {
        if (null !== $name && '' !== trim($name)) {
            $stored = $this->getEndpoint($name);
            if (null !== $stored) {
                $baseUrl = $baseUrl ?? $stored['base_url'];
                $apiKey = $apiKey ?? $stored['api_key'];
                $headers = [] !== $headers ? $headers : $stored['headers'];
            }
        }

        $result = $this->fetchModelCatalog($baseUrl, $apiKey, $this->normalizeHeaders($headers));
        if (!$result['ok']) {
            $out = ['ok' => false, 'error' => $result['error'] ?? 'Upstream did not answer'];
            if (isset($result['status'])) {
                $out['status'] = $result['status'];
            }

            return $out;
        }

        $ids = [];
        foreach ($result['models'] as $model) {
            $ids[] = $model['id'];
        }

        return [
            'ok' => true,
            'status' => $result['status'] ?? 200,
            'model_count' => count($ids),
            'sample' => array_slice($ids, 0, 10),
        ];
    }

    /**
     * List the model ids a stored endpoint offers (GET {base}/models).
     *
     * Returns a result rather than throwing so callers can tell an unreachable
     * endpoint (`ok = false`) apart from one that lists nothing (`ok = true`,
     * empty `ids`) — the distinction the import re-check (S5) relies on.
     * Ids only: pricing lives on {@see listModelCatalog()} so existing callers
     * keep this shape.
     *
     * @return array{ok: bool, ids: list<string>, status?: int, error?: string}
     */
    public function listModelIds(string $name): array
    {
        $catalog = $this->listModelCatalog($name);
        $ids = [];
        foreach ($catalog['models'] as $model) {
            $ids[] = $model['id'];
        }

        $out = ['ok' => $catalog['ok'], 'ids' => $ids];
        if (isset($catalog['status'])) {
            $out['status'] = $catalog['status'];
        }
        if (isset($catalog['error'])) {
            $out['error'] = $catalog['error'];
        }

        return $out;
    }

    /**
     * Same listing as {@see listModelIds()}, plus the display name and the
     * published per-1M price when the gateway sends one.
     *
     * @return array{
     *     ok: bool,
     *     models: list<array{id: string, name: string|null, priceInPerMillion: float|null, priceOutPerMillion: float|null, priceKnown: bool}>,
     *     status?: int,
     *     error?: string
     * }
     */
    public function listModelCatalog(string $name): array
    {
        $endpoint = $this->getEndpoint($name);
        if (null === $endpoint) {
            return ['ok' => false, 'models' => [], 'error' => 'Unknown endpoint: '.$name];
        }

        return $this->fetchModelCatalog($endpoint['base_url'], $endpoint['api_key'], $endpoint['headers']);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{
     *     ok: bool,
     *     models: list<array{id: string, name: string|null, priceInPerMillion: float|null, priceOutPerMillion: float|null, priceKnown: bool}>,
     *     status?: int,
     *     error?: string
     * }
     */
    private function fetchModelCatalog(?string $baseUrl, ?string $apiKey, array $headers): array
    {
        try {
            $upstream = $this->resolveUpstream((string) $baseUrl);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'models' => [], 'error' => $e->getMessage()];
        }

        $requestHeaders = $headers;
        if (null !== $apiKey && '' !== $apiKey) {
            $requestHeaders['Authorization'] = 'Bearer '.$apiKey;
        }

        $options = [
            'headers' => $requestHeaders,
            'timeout' => 10,
            // A redirect could lead off the address that was just validated.
            'max_redirects' => 0,
        ];
        if (null !== $upstream['pin']) {
            $options['resolve'] = [$upstream['host'] => $upstream['pin']];
        }

        try {
            $response = $this->httpClient->request('GET', $upstream['url'].'/models', $options);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $response->cancel();

                return ['ok' => false, 'models' => [], 'status' => $status, 'error' => 'Upstream redirected the request. Enter the final base URL instead.'];
            }
            if ($status >= 400) {
                return ['ok' => false, 'models' => [], 'status' => $status, 'error' => 'Upstream returned HTTP '.$status];
            }

            $body = $response->toArray(false);
            $models = [];
            foreach ($body['data'] ?? [] as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_string($item['id']) || '' === $item['id']) {
                    continue;
                }
                $price = ListedModelPrice::fromListingItem($item);
                $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
                $models[] = [
                    'id' => $item['id'],
                    'name' => '' !== $name ? $name : null,
                    'priceInPerMillion' => $price->priceInPerMillion,
                    'priceOutPerMillion' => $price->priceOutPerMillion,
                    'priceKnown' => $price->known,
                ];
            }

            return ['ok' => true, 'models' => $models, 'status' => $status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'models' => [], 'error' => $e->getMessage()];
        }
    }

    public function hasAnyEndpoint(): bool
    {
        return [] !== $this->rawRows();
    }

    /**
     * Public listing — never exposes the raw API key, only whether one is set.
     *
     * @return array<int, array{name: string, label: string, base_url: string, has_api_key: bool, headers: array<string, string>, capabilities: string[]}>
     */
    public function listEndpoints(): array
    {
        $out = [];
        foreach ($this->rawRows() as $name => $payload) {
            $out[] = [
                'name' => $name,
                'label' => (string) ($payload['label'] ?? $name),
                'base_url' => (string) ($payload['base_url'] ?? ''),
                'has_api_key' => '' !== (string) ($payload['api_key'] ?? ''),
                'headers' => $this->normalizeHeaders($payload['headers'] ?? []),
                'capabilities' => $this->normalizeCapabilities($payload['capabilities'] ?? []),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Full endpoint config INCLUDING the decrypted API key. For internal
     * provider use only — never return this to an HTTP client.
     *
     * @return array{name: string, label: string, base_url: string, api_key: string, headers: array<string, string>, capabilities: string[]}|null
     */
    public function getEndpoint(string $name): ?array
    {
        $name = strtolower(trim($name));
        $payload = $this->rawRows()[$name] ?? null;
        if (null === $payload) {
            return null;
        }

        return [
            'name' => $name,
            'label' => (string) ($payload['label'] ?? $name),
            'base_url' => (string) ($payload['base_url'] ?? ''),
            'api_key' => (string) ($payload['api_key'] ?? ''),
            'headers' => $this->normalizeHeaders($payload['headers'] ?? []),
            'capabilities' => $this->normalizeCapabilities($payload['capabilities'] ?? []),
        ];
    }

    /**
     * Resolve the endpoint to use for a chat/embed/vision call.
     *
     * Resolution order:
     *   1. Explicit endpoint name passed in options (advanced override).
     *   2. The endpoint named in the BMODELS row's BJSON for this providerId.
     *   3. The single configured endpoint, when exactly one exists.
     *
     * @return array{name: string, label: string, base_url: string, api_key: string, headers: array<string, string>, capabilities: string[]}|null
     */
    public function resolveForModel(?string $providerId, ?string $explicitEndpoint = null): ?array
    {
        if (null !== $explicitEndpoint && '' !== trim($explicitEndpoint)) {
            $endpoint = $this->getEndpoint($explicitEndpoint);
            if (null !== $endpoint) {
                return $endpoint;
            }
        }

        if (null !== $providerId && '' !== trim($providerId)) {
            $model = $this->modelRepository->findByServiceAndProviderId(self::SERVICE, $providerId);
            $endpointName = $model?->getJson()['endpoint'] ?? null;
            if (is_string($endpointName) && '' !== $endpointName) {
                $endpoint = $this->getEndpoint($endpointName);
                if (null !== $endpoint) {
                    return $endpoint;
                }
            }
        }

        $rows = $this->rawRows();
        if (1 === count($rows)) {
            $only = array_key_first($rows);

            return $this->getEndpoint((string) $only);
        }

        return null;
    }

    /**
     * Create or update an endpoint. A null $apiKey preserves the currently
     * stored key (so an admin can edit the URL/headers without re-entering the
     * secret); pass an empty string to explicitly clear it.
     *
     * @param array<string, string> $headers
     * @param string[]              $capabilities
     */
    public function saveEndpoint(
        string $name,
        string $baseUrl,
        ?string $apiKey,
        array $headers = [],
        ?string $label = null,
        array $capabilities = [],
    ): void {
        $name = strtolower(trim($name));
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Invalid endpoint name. Use lowercase letters, digits, "-" and "_" (max 63 chars).');
        }

        $upstream = $this->resolveUpstream($baseUrl);

        $existing = $this->rawRows()[$name] ?? null;
        $resolvedKey = null === $apiKey ? (string) ($existing['api_key'] ?? '') : $apiKey;

        $payload = [
            'label' => null !== $label && '' !== trim($label) ? trim($label) : $name,
            'base_url' => $upstream['url'],
            'api_key' => $resolvedKey,
            'headers' => $this->normalizeHeaders($headers),
            'capabilities' => $this->normalizeCapabilities($capabilities ?: self::CAPABILITIES),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::SETTING_PREFIX.$name, $this->encryption->encrypt($json));

        $this->logger->info('OpenAI-compatible endpoint saved', [
            'name' => $name,
            'base_url' => $payload['base_url'],
            'has_api_key' => '' !== $resolvedKey,
        ]);
    }

    public function deleteEndpoint(string $name): bool
    {
        $name = strtolower(trim($name));

        return $this->configRepository->deleteValue(0, self::CONFIG_GROUP, self::SETTING_PREFIX.$name);
    }

    /**
     * Validate a base URL against the upstream policy and resolve the address
     * the probe must connect to.
     *
     * `pin` is the address to connect to when the host is a name (null for a
     * literal IP, which needs no pinning). The message of the exception is
     * shown to the administrator as-is, so it names what to do next.
     *
     * @return array{url: string, host: string, pin: ?string}
     *
     * @throws \InvalidArgumentException when the URL is malformed or refused
     */
    private function resolveUpstream(string $baseUrl): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = '' !== $baseUrl ? parse_url($baseUrl) : false;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        if ('' === $host || !in_array($scheme, self::ALLOWED_SCHEMES, true) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('base_url must be an absolute http:// or https:// URL (e.g. https://localai.example.com/v1).');
        }

        $isLiteralIp = false !== filter_var($host, \FILTER_VALIDATE_IP);

        if ($this->publicUpstreamsOnly) {
            if ($this->ssrfGuard->isBlockedUrl($baseUrl)) {
                throw new \InvalidArgumentException(sprintf('base_url points at a private or reserved network. This installation only accepts public upstreams (%s is set).', self::ENV_PUBLIC_ONLY));
            }
            $pinned = $this->ssrfGuard->pinnedIps($host);
        } else {
            $pinned = $this->ssrfGuard->pinnedIps($host, allowOperatorNetwork: true);
        }

        if ([] === $pinned && $isLiteralIp) {
            throw new \InvalidArgumentException('base_url points at a reserved address (link-local, metadata or unroutable) and was refused.');
        }
        if ([] === $pinned) {
            throw new \InvalidArgumentException('base_url host could not be resolved to an address this installation may connect to. Check the hostname, or use the gateway\'s IP address.');
        }

        return [
            'url' => $baseUrl,
            'host' => $host,
            'pin' => $isLiteralIp ? null : $pinned[0],
        ];
    }

    /**
     * Decrypt and decode every stored endpoint row.
     *
     * @return array<string, array<string, mixed>> keyed by endpoint name
     */
    private function rawRows(): array
    {
        $rows = $this->configRepository->findBy([
            'ownerId' => 0,
            'group' => self::CONFIG_GROUP,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $setting = $row->getSetting();
            if (!str_starts_with($setting, self::SETTING_PREFIX)) {
                continue;
            }
            $name = substr($setting, strlen(self::SETTING_PREFIX));
            $cipher = (string) $row->getValue();
            if ('' === $cipher) {
                continue;
            }

            try {
                $decoded = json_decode($this->encryption->decrypt($cipher), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                // A decrypt/decode failure usually means APP_SECRET changed
                // since the row was written. Skip it rather than break every
                // request — and log so operators see the cause.
                $this->logger->error('OpenAI-compatible endpoint decode failed, skipping', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (is_array($decoded)) {
                $out[$name] = $decoded;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $out = [];
        foreach ($headers as $key => $value) {
            if (is_string($key) && '' !== trim($key) && (is_string($value) || is_numeric($value))) {
                $out[trim($key)] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function normalizeCapabilities(mixed $capabilities): array
    {
        if (!is_array($capabilities)) {
            return [];
        }

        $out = [];
        foreach ($capabilities as $cap) {
            $cap = strtolower(trim((string) $cap));
            if (in_array($cap, self::CAPABILITIES, true) && !in_array($cap, $out, true)) {
                $out[] = $cap;
            }
        }

        return $out;
    }
}
