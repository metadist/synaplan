<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Install-wide registry of "OpenAI Compatible" upstream endpoints (LocalAI,
 * vLLM, LiteLLM, Ollama's /v1, any gateway that speaks the OpenAI Chat
 * Completions + Embeddings API).
 *
 * An endpoint is a named tuple {base_url, api_key, headers, label,
 * capabilities}. It is stored globally (ownerId = 0) in BCONFIG under the
 * group {@see self::CONFIG_GROUP}, one row per endpoint (setting =
 * "endpoint.<name>"), with the whole JSON payload encrypted at rest via
 * {@see EncryptionService} (which derives its key from APP_SECRET) — the same
 * pattern used for per-user Higgsfield credentials.
 *
 * A BMODELS row of service {@see self::SERVICE} references its endpoint by
 * name in BJSON ("endpoint": "<name>"); the model's providerId stays the
 * upstream model id sent in the request body. This lets one Synaplan install
 * expose several models across several self-hosted gateways.
 */
final class OpenAiCompatibleEndpointRegistry
{
    /** BSERVICE value stored on BMODELS rows served by this provider. */
    public const SERVICE = 'OpenAICompatible';

    /** Registry key {@see \App\AI\Service\ProviderRegistry} uses (lowercased service). */
    public const PROVIDER_NAME = 'openaicompatible';

    public const CONFIG_GROUP = 'openai_compatible';

    private const SETTING_PREFIX = 'endpoint.';

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    /** Capabilities an endpoint may advertise (maps to BMODELS.BTAG values). */
    public const CAPABILITIES = ['chat', 'vectorize', 'pic2text'];

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ModelRepository $modelRepository,
        private readonly EncryptionService $encryption,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
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

        $baseUrl = rtrim((string) $baseUrl, '/');
        if ('' === $baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'base_url must be a valid absolute URL'];
        }

        $requestHeaders = $this->normalizeHeaders($headers);
        if (null !== $apiKey && '' !== $apiKey) {
            $requestHeaders['Authorization'] = 'Bearer '.$apiKey;
        }

        try {
            $response = $this->httpClient->request('GET', $baseUrl.'/models', [
                'headers' => $requestHeaders,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($status >= 400) {
                return ['ok' => false, 'status' => $status, 'error' => 'Upstream returned HTTP '.$status];
            }

            $ids = [];
            foreach ($body['data'] ?? [] as $item) {
                if (isset($item['id']) && is_string($item['id'])) {
                    $ids[] = $item['id'];
                }
            }

            return [
                'ok' => true,
                'status' => $status,
                'model_count' => count($ids),
                'sample' => array_slice($ids, 0, 10),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
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

        $baseUrl = trim($baseUrl);
        if ('' === $baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('base_url must be a valid absolute URL (e.g. https://localai.example.com/v1).');
        }

        $existing = $this->rawRows()[$name] ?? null;
        $resolvedKey = null === $apiKey ? (string) ($existing['api_key'] ?? '') : $apiKey;

        $payload = [
            'label' => null !== $label && '' !== trim($label) ? trim($label) : $name,
            'base_url' => rtrim($baseUrl, '/'),
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
