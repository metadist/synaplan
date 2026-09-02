<?php

declare(strict_types=1);

namespace App\AI\Messages\Translator;

use App\AI\Messages\AnthropicJsonSchemaNormalizer;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Forwards Anthropic Messages requests to the configured upstream verbatim.
 *
 * Raw-body fast path: when context['raw_body'] is set and the body was not
 * mutated, the exact request bytes are forwarded (beta fields, attribution
 * block, cache_control markers stay byte-identical). Otherwise the decoded
 * $requestBody is re-encoded with JSON_THROW_ON_ERROR after
 * {@see AnthropicJsonSchemaNormalizer} restores empty schema objects that PHP
 * would otherwise emit as JSON arrays.
 *
 * Streaming emits raw SSE byte chunks via $emit(string) while teeing them
 * to extract usage from message_start / message_delta events.
 */
final readonly class AnthropicPassthroughTranslator implements MessagesTranslatorInterface
{
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_TIMEOUT = 600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private AnthropicJsonSchemaNormalizer $schemaNormalizer,
    ) {
    }

    public function supports(string $providerName): bool
    {
        return 'anthropic' === strtolower($providerName);
    }

    public function complete(array $requestBody, array $context): array
    {
        $response = $this->request($requestBody, $context, stream: false);
        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $raw = $response->getContent(false);

        $decoded = json_decode($raw, true);
        $usage = MessagesUsage::fromAnthropicUsage(
            \is_array($decoded) ? ($decoded['usage'] ?? []) : [],
            \is_array($decoded) ? ($decoded['stop_reason'] ?? null) : null,
        );

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => \is_array($decoded) ? $decoded : $raw,
            'usage' => $usage,
        ];
    }

    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage
    {
        $response = $this->request($requestBody, $context, stream: true);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            // Emit the upstream error body unmodified (Claude Code recovery
            // matches on error wording) then return empty usage.
            $errorBody = $response->getContent(false);
            if (!empty($context['parsed_events'])) {
                $decoded = json_decode($errorBody, true);
                $emit([
                    'event' => 'error',
                    'data' => \is_array($decoded) ? $decoded : [
                        'type' => 'error',
                        'error' => ['type' => 'api_error', 'message' => $errorBody],
                    ],
                ]);
            } else {
                $emit($errorBody);
            }

            $this->logger->warning('MessagesGateway: upstream Anthropic stream error', [
                'status' => $status,
            ]);

            return new MessagesUsage();
        }

        if (!empty($context['parsed_events'])) {
            return $this->relayParsedEvents($response, $emit);
        }

        return $this->relayAndTee($response, $emit);
    }

    /**
     * @param array<string, mixed> $requestBody
     * @param array{
     *     api_key: string,
     *     upstream_url: string,
     *     anthropic_version?: string|null,
     *     anthropic_beta?: string|null,
     *     x_fixture?: string|null,
     *     raw_body?: string|null,
     *     parsed_events?: bool
     * } $context
     */
    private function request(array $requestBody, array $context, bool $stream): ResponseInterface
    {
        $upstream = rtrim($context['upstream_url'], '/');
        $url = $upstream.'/v1/messages';

        $headers = [
            'x-api-key' => $context['api_key'],
            'content-type' => 'application/json',
            'anthropic-version' => $context['anthropic_version'] ?: self::API_VERSION,
        ];
        if (!empty($context['anthropic_beta'])) {
            $headers['anthropic-beta'] = $context['anthropic_beta'];
        }
        // Dev/smoke-harness only: fixture-upstream.php selects transcripts via
        // X-Fixture. Never sent to api.anthropic.com in normal use.
        if (!empty($context['x_fixture'])) {
            $headers['x-fixture'] = $context['x_fixture'];
        }
        if ($stream) {
            $headers['accept'] = 'text/event-stream';
        }

        $body = $context['raw_body'] ?? null;
        if (null === $body || '' === $body) {
            $payload = $this->schemaNormalizer->normalizeRequestBody($requestBody);
            $payload['stream'] = $stream;
            $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif ($stream) {
            // Ensure stream:true even on the raw path when the client asked
            // for streaming but the raw JSON had stream:false (shouldn't
            // happen for Claude Code, but keep the contract).
            $decoded = json_decode($body, true);
            if (\is_array($decoded) && true !== ($decoded['stream'] ?? false)) {
                $decoded['stream'] = true;
                $decoded = $this->schemaNormalizer->normalizeRequestBody($decoded);
                $body = json_encode($decoded, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        return $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => self::DEFAULT_TIMEOUT,
            'buffer' => !$stream,
        ]);
    }

    /**
     * @param callable(string): void $emit
     */
    private function relayAndTee(ResponseInterface $response, callable $emit): MessagesUsage
    {
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreation = 0;
        $cacheCreation1h = 0;
        $cacheRead = 0;
        $stopReason = null;
        $eventName = null;
        $dataBuffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            set_time_limit(0);

            if ($chunk->isTimeout()) {
                continue;
            }

            $content = $chunk->getContent();
            if ('' === $content) {
                continue;
            }

            $emit($content);

            // Tee: parse SSE lines for usage. Tolerate partial lines across chunks.
            $dataBuffer .= $content;
            while (false !== ($pos = strpos($dataBuffer, "\n"))) {
                $line = substr($dataBuffer, 0, $pos);
                $dataBuffer = substr($dataBuffer, $pos + 1);
                $line = rtrim($line, "\r");

                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $payload = trim(substr($line, 5));
                    if ('' === $payload || '[DONE]' === $payload) {
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!\is_array($decoded)) {
                        continue;
                    }

                    $type = $decoded['type'] ?? $eventName;
                    if ('message_start' === $type) {
                        $usage = $decoded['message']['usage'] ?? [];
                        $inputTokens = (int) ($usage['input_tokens'] ?? $inputTokens);
                        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? $cacheCreation);
                        $cacheCreation1h = \is_array($usage) ? MessagesUsage::extractCacheCreation1hTokens($usage) : $cacheCreation1h;
                        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $cacheRead);
                    } elseif ('message_delta' === $type) {
                        $usage = $decoded['usage'] ?? [];
                        $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                        $delta = $decoded['delta'] ?? [];
                        if (isset($delta['stop_reason']) && \is_string($delta['stop_reason'])) {
                            $stopReason = $delta['stop_reason'];
                        }
                    }
                }
            }
        }

        return new MessagesUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationTokens: $cacheCreation,
            cacheCreation1hTokens: $cacheCreation1h,
            cacheReadTokens: $cacheRead,
            stopReason: $stopReason,
        );
    }

    /**
     * Parse upstream SSE into Anthropic-shaped event arrays for the MCP tool
     * loop (which needs structured events to remap indices / suppress stops).
     *
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    private function relayParsedEvents(ResponseInterface $response, callable $emit): MessagesUsage
    {
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreation = 0;
        $cacheCreation1h = 0;
        $cacheRead = 0;
        $stopReason = null;
        $eventName = null;
        $dataBuffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            set_time_limit(0);

            if ($chunk->isTimeout()) {
                continue;
            }

            $content = $chunk->getContent();
            if ('' === $content) {
                continue;
            }

            $dataBuffer .= $content;
            while (false !== ($pos = strpos($dataBuffer, "\n"))) {
                $line = substr($dataBuffer, 0, $pos);
                $dataBuffer = substr($dataBuffer, $pos + 1);
                $line = rtrim($line, "\r");

                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $payload = trim(substr($line, 5));
                    if ('' === $payload || '[DONE]' === $payload) {
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!\is_array($decoded)) {
                        continue;
                    }

                    $type = (string) ($decoded['type'] ?? $eventName ?? 'message');
                    $emit([
                        'event' => $eventName ?? $type,
                        'data' => $decoded,
                    ]);

                    if ('message_start' === $type) {
                        $usage = $decoded['message']['usage'] ?? [];
                        $inputTokens = (int) ($usage['input_tokens'] ?? $inputTokens);
                        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? $cacheCreation);
                        $cacheCreation1h = \is_array($usage) ? MessagesUsage::extractCacheCreation1hTokens($usage) : $cacheCreation1h;
                        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $cacheRead);
                    } elseif ('message_delta' === $type) {
                        $usage = $decoded['usage'] ?? [];
                        $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                        $delta = $decoded['delta'] ?? [];
                        if (isset($delta['stop_reason']) && \is_string($delta['stop_reason'])) {
                            $stopReason = $delta['stop_reason'];
                        }
                    }

                    $eventName = null;
                }
            }
        }

        return new MessagesUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationTokens: $cacheCreation,
            cacheCreation1hTokens: $cacheCreation1h,
            cacheReadTokens: $cacheRead,
            stopReason: $stopReason,
        );
    }
}
