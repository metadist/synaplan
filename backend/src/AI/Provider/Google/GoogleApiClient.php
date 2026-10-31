<?php

namespace App\AI\Provider\Google;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The Gemini API transport the Google capability classes share: the API
 * key (an explicit override, else the provider key store, so a key saved in
 * the admin UI applies without a restart), the endpoints of the Gemini API
 * and of Vertex AI, the authenticated requests, and the check that turns a
 * blocked answer into a ProviderException.
 */
final class GoogleApiClient
{
    public const PROVIDER = 'google';
    public const KEY_ENV = 'GOOGLE_GEMINI_API_KEY';

    /** The Gemini API base; the provider descriptor lists it as an endpoint. */
    public const GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const API_BASE = self::GEMINI_BASE;
    private const VERTEX_BASE = 'https://{region}-aiplatform.googleapis.com/v1';

    /** The Vertex AI base for a region; the descriptor lists the configured one. */
    public static function vertexBase(string $region): string
    {
        return str_replace('{region}', $region, self::VERTEX_BASE);
    }

    private readonly ?string $projectId;
    private readonly ?string $vertexAccessToken;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly ?ProviderKeyStore $keyStore = null,
        ?string $projectId = null,
        private readonly string $region = 'us-central1',
        ?string $vertexAccessToken = null,
    ) {
        $this->projectId = empty($projectId) ? null : $projectId;
        $this->vertexAccessToken = empty($vertexAccessToken) ? null : $vertexAccessToken;
    }

    public function key(): ?string
    {
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            return $this->apiKey;
        }

        return $this->keyStore?->getKey(self::PROVIDER);
    }

    public function requireKey(): string
    {
        return $this->key() ?? throw ProviderException::missingApiKey(self::PROVIDER, self::KEY_ENV);
    }

    /**
     * A model method on the Gemini API, e.g. `generateContent` or
     * `streamGenerateContent?alt=sse`.
     */
    public function modelUrl(string $model, string $method): string
    {
        return self::API_BASE."/models/{$model}:{$method}";
    }

    /**
     * A resource of the Gemini API: a long-running operation, an interaction.
     */
    public function url(string $path): string
    {
        return self::API_BASE.'/'.$path;
    }

    /**
     * Vertex AI needs a GCP project and its own OAuth access token; the
     * Gemini API key is never sent there as a bearer.
     */
    public function usesVertex(): bool
    {
        return null !== $this->projectId && null !== $this->vertexAccessToken;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function vertexAccessToken(): ?string
    {
        return $this->vertexAccessToken;
    }

    public function vertexModelUrl(string $model, string $method): string
    {
        return str_replace('{region}', $this->region, self::VERTEX_BASE)
            ."/projects/{$this->projectId}/locations/{$this->region}"
            ."/publishers/google/models/{$model}:{$method}";
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options further request options, e.g. `max_duration`
     */
    public function postJson(string $url, array $payload, string $key, int $timeout, array $options = []): ResponseInterface
    {
        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $key,
            ],
            'json' => $payload,
            'timeout' => $timeout,
        ] + $options);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function postVertexJson(string $url, array $payload, int $timeout): ResponseInterface
    {
        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->vertexAccessToken,
            ],
            'json' => $payload,
            'timeout' => $timeout,
        ]);
    }

    /**
     * A request without a body: a status poll, a download, a cancel.
     */
    public function send(string $method, string $url, string $key, int $timeout): ResponseInterface
    {
        return $this->httpClient->request($method, $url, [
            'headers' => ['x-goog-api-key' => $key],
            'timeout' => $timeout,
        ]);
    }

    public function stream(ResponseInterface $response): ResponseStreamInterface
    {
        return $this->httpClient->stream($response);
    }

    /**
     * Check a generateContent answer for blocked content.
     *
     * Gemini returns finishReason codes like SAFETY, RECITATION, PROHIBITED_CONTENT
     * when it refuses to generate. The HTTP status is still 200, but the response
     * contains no usable content parts.
     *
     * @param array<string, mixed> $data
     *
     * @throws ProviderException when content was blocked
     */
    public function checkFinishReason(array $data): void
    {
        $candidate = $data['candidates'][0] ?? null;

        if (!$candidate) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? null;
            if ($blockReason) {
                $this->logger->warning('Google Gemini: Prompt blocked', [
                    'block_reason' => $blockReason,
                    'prompt_feedback' => $data['promptFeedback'] ?? null,
                ]);
                throw ProviderException::contentBlocked('google', $blockReason);
            }

            return;
        }

        $finishReason = $candidate['finishReason'] ?? null;

        if ($finishReason && !\in_array($finishReason, ['STOP', 'MAX_TOKENS', 'END_TURN', 'FUNCTION_CALL'], true)) {
            $textResponse = null;
            $parts = $candidate['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $textResponse = $part['text'];
                    break;
                }
            }

            $this->logger->warning('Google Gemini: Content blocked', [
                'finish_reason' => $finishReason,
                'safety_ratings' => $candidate['safetyRatings'] ?? null,
                'text_response' => $textResponse ? substr($textResponse, 0, 300) : null,
            ]);

            throw ProviderException::contentBlocked('google', $finishReason, $textResponse);
        }
    }
}
