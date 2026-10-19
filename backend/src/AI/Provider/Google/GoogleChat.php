<?php

namespace App\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\Interface\ChatProviderInterface;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Gemini chat for GoogleProvider: buffered and streamed generateContent
 * with tool calling, structured output and the reasoning budget, one retry
 * on a transient 503, and the stream's chunks mapped to the provider
 * callback (text, reasoning, tool-call deltas, finish).
 */
final class GoogleChat
{
    /**
     * Smallest non-zero thinking budget for a chosen "low" level.
     * Zero disables thinking on Flash, so low has to stay above it and
     * below the medium budget (1024).
     */
    private const LOW_THINKING_BUDGET = 512;

    public function __construct(
        private readonly GoogleApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly StructuredOutputTranslator $structuredOutputTranslator,
    ) {
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        $key = $this->api->requireKey();

        try {
            $model = $options['model'];
            $contents = GoogleMessageFormat::contents($messages);

            $url = $this->api->modelUrl($model, 'generateContent');

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'topP' => $options['top_p'] ?? 0.95,
                    'topK' => $options['top_k'] ?? 40,
                    'maxOutputTokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
                ],
            ];
            $payload = GoogleMessageFormat::withTools($payload, $options);

            $payload = $this->applyStructuredOutput($payload, $options, $model, false);

            $this->logger->info('Google: Generating chat completion', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $response = $this->api->postJson($url, $payload, $key, 60);

            $data = $response->toArray();

            $this->api->checkFinishReason($data);

            $usageMetadata = $data['usageMetadata'] ?? [];
            $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
            $completionTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
            $cachedTokens = $usageMetadata['cachedContentTokenCount'] ?? 0;

            $usage = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'cached_tokens' => $cachedTokens,
                'cache_creation_tokens' => 0,
            ];

            $textContent = '';
            $toolCalls = [];
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                    $toolCalls[] = GoogleMessageFormat::toolCall($part['functionCall']);
                    continue;
                }
                if (isset($part['text']) && empty($part['thought'])) {
                    $textContent .= $part['text'];
                }
            }

            $result = [
                'content' => $textContent,
                'usage' => $usage,
            ];
            if ([] !== $toolCalls) {
                $result['tool_calls'] = $toolCalls;
                $result['finish_reason'] = 'tool_calls';
            } elseif ('MAX_TOKENS' === ($data['candidates'][0]['finishReason'] ?? null)) {
                $result['finish_reason'] = 'length';
            }

            return $result;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, 'google', 'chat');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        $key = $this->api->requireKey();

        try {
            $model = $options['model'];
            $payload = $this->streamPayload($messages, $options, $model);

            $this->logger->info('Google: Streaming chat completion', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            // Single auto-retry for transient UNAVAILABLE / 503 — Gemini's
            // edge sometimes blips even when capacity is healthy, and
            // Pro-tier models in particular are prone to short overload
            // windows. We do exactly ONE retry after 1s; further failures
            // surface to the user so they can switch models or wait.
            // Only the request/header phase is retried — once chunks start
            // flowing, we never restart mid-stream.
            $response = $this->requestWithSingleRetryOn503($this->api->modelUrl($model, 'streamGenerateContent?alt=sse'), $payload, $key, $model);

            return ['usage' => $this->readStream($response, $callback)];
        } catch (ProviderException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->streamFailure($e, (string) $options['model']);
        } catch (\Exception $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, 'google', 'chat_stream', 'Google streaming error');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return array<string, mixed>
     */
    private function streamPayload(array $messages, array $options, string $model): array
    {
        $generationConfig = [
            'temperature' => $options['temperature'] ?? 0.7,
            'topP' => $options['top_p'] ?? 0.95,
            'topK' => $options['top_k'] ?? 40,
            'maxOutputTokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
        ];

        // Phase 1e: Gemini 2.5+ Pro / 3.x "thinking" models otherwise
        // burn 5-10s on internal reasoning before emitting the first
        // visible token. Map an optional `reasoning_effort` knob onto
        // Google's `thinkingConfig.thinkingBudget` so chat (default
        // `low`) gets near-instant TTFT on FLASH models, while leaving
        // Pro models (which mandate non-zero thinking) on Google's
        // server-side default.
        //
        // Budget interpretation (per Google docs):
        //   - 0      → thinking disabled (Flash only — Pro rejects this with HTTP 4xx)
        //   - >0     → token budget reserved for hidden reasoning
        //   - -1     → "dynamic" — model decides
        $thinkingConfig = $this->resolveThinkingConfig($options, $model);
        if (null !== $thinkingConfig) {
            $generationConfig['thinkingConfig'] = $thinkingConfig;
        }

        $payload = [
            'contents' => GoogleMessageFormat::contents($messages),
            'generationConfig' => $generationConfig,
        ];
        $payload = GoogleMessageFormat::withTools($payload, $options);

        return $this->applyStructuredOutput($payload, $options, $model, true);
    }

    /**
     * Read the SSE stream: text, reasoning and tool-call deltas go to the
     * callback as they arrive and the finish reason after the last chunk;
     * the usage of the final chunk is returned.
     *
     * @return array<string, int>
     */
    private function readStream(ResponseInterface $response, callable $callback): array
    {
        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];
        $finishReason = null;
        $sawFunctionCall = false;
        $toolIndex = 0;

        foreach ($this->api->stream($response) as $chunk) {
            if ($chunk->isLast()) {
                break;
            }

            $content = $chunk->getContent();

            // Parse SSE format: data: {...}
            if (str_starts_with($content, 'data: ')) {
                $jsonData = substr($content, 6);
                $data = json_decode($jsonData, true);

                if ($data) {
                    $this->api->checkFinishReason($data);
                }

                // Capture usage from each chunk (last chunk has final values)
                if (isset($data['usageMetadata'])) {
                    $usageMetadata = $data['usageMetadata'];
                    $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
                    $completionTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
                    $cachedTokens = $usageMetadata['cachedContentTokenCount'] ?? 0;
                    $usage = [
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'total_tokens' => $promptTokens + $completionTokens,
                        'cached_tokens' => $cachedTokens,
                        'cache_creation_tokens' => 0,
                    ];
                }

                $parts = $data['candidates'][0]['content']['parts'] ?? [];
                foreach ($parts as $part) {
                    if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                        $call = GoogleMessageFormat::toolCall($part['functionCall']);
                        $callback([
                            'type' => 'tool_call_delta',
                            'index' => $toolIndex,
                            'id' => $call['id'],
                            'name' => $call['function']['name'],
                            'arguments' => $call['function']['arguments'],
                        ]);
                        ++$toolIndex;
                        $sawFunctionCall = true;
                        continue;
                    }

                    if (!isset($part['text']) || '' === $part['text']) {
                        continue;
                    }

                    if (!empty($part['thought'])) {
                        $callback(['type' => 'reasoning', 'content' => $part['text']]);
                    } else {
                        $callback($part['text']);
                    }
                }

                // Finish reason after parts so FUNCTION_CALL / STOP+functionCall
                // in the same chunk map to tool_calls.
                $geminiFinishReason = $data['candidates'][0]['finishReason'] ?? null;
                if (null !== $geminiFinishReason) {
                    $finishReason = match ($geminiFinishReason) {
                        'MAX_TOKENS' => 'length',
                        'FUNCTION_CALL' => 'tool_calls',
                        'STOP', 'END_TURN' => $sawFunctionCall ? 'tool_calls' : 'stop',
                        default => $geminiFinishReason,
                    };
                }
            }
        }

        if (null !== $finishReason) {
            $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
        }

        return $usage;
    }

    private function streamFailure(HttpExceptionInterface $e, string $model): ProviderException
    {
        // Surface the actual Google response body so the user sees the
        // root cause ("API key invalid", "quota exhausted", "model
        // overloaded", etc.) instead of a bare "HTTP/2 503 returned"
        // message that wastes a debug round-trip.
        $body = '';
        $statusCode = 0;
        try {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getContent(false);
        } catch (\Throwable) {
            // ignore — fall through with empty body
        }
        $body = trim($body);

        $this->logger->error('Google: streamGenerateContent failed', [
            'error' => $e->getMessage(),
            'status_code' => $statusCode,
            'body' => $body,
        ]);

        $factory = new ProviderFailureFactory();

        // Friendlier user-facing message for model-overload (503 /
        // UNAVAILABLE) since that's the most common transient failure
        // and the user can act on it (switch model / retry).
        if (503 === $statusCode || (false !== stripos($body, 'UNAVAILABLE') && false !== stripos($body, 'high demand'))) {
            return $factory->fromParsed(sprintf('Google AI model "%s" is currently overloaded. Please retry in a few seconds, or switch to another model.', $model), 'google', 'chat_stream', $statusCode, previous: $e);
        }

        $detail = '' !== $body ? ' — '.mb_substr($body, 0, 500) : '';

        return $factory->fromParsed('Google streaming error: '.$e->getMessage().$detail, 'google', 'chat_stream', $statusCode, previous: $e);
    }

    /**
     * Merge a {@see StructuredOutputSchema} (if present in `$options`) into
     * an already-built Gemini request payload.
     *
     * The translator returns `{'generationConfig': {responseMimeType,
     * responseJsonSchema}}`, which must be merged INTO the payload's existing
     * `generationConfig` (temperature, topP, thinkingConfig, …) rather than
     * replacing it — a flat array_merge() of the whole payload would drop
     * every generation parameter built above.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function applyStructuredOutput(array $payload, array $options, string $model, bool $stream): array
    {
        $schema = $options['structured_output'] ?? null;
        if (!$schema instanceof StructuredOutputSchema) {
            return $payload;
        }

        $translated = $this->structuredOutputTranslator->translate(GoogleApiClient::PROVIDER, $model, $stream, $schema);
        if (isset($translated['generationConfig']) && is_array($translated['generationConfig'])) {
            $payload['generationConfig'] = array_merge($payload['generationConfig'] ?? [], $translated['generationConfig']);
            unset($translated['generationConfig']);
        }

        return array_merge($payload, $translated);
    }

    /**
     * Issue a Google API request with one transparent retry on 503/UNAVAILABLE.
     *
     * The Symfony HttpClient response is lazy — we trigger the status check
     * via `getStatusCode()` so we can detect the failure before any chunks
     * are consumed. On 503 we wait 1 s then issue the request once more;
     * any further failure (or any non-503 error) propagates so the
     * higher-level catch can surface a friendly message.
     *
     * @param array<string, mixed> $payload
     */
    private function requestWithSingleRetryOn503(string $url, array $payload, string $key, string $model): ResponseInterface
    {
        $maxAttempts = 2;
        $attempt = 0;

        while (true) {
            ++$attempt;
            $response = $this->api->postJson($url, $payload, $key, 120);

            try {
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                // Transport error before headers — return the response and
                // let the streaming loop surface the underlying exception.
                return $response;
            }

            if (503 !== $status || $attempt >= $maxAttempts) {
                return $response;
            }

            $this->logger->warning('Google: 503 from streamGenerateContent — retrying once', [
                'model' => $model,
                'attempt' => $attempt,
            ]);

            // Free the connection before sleeping so the retry can reuse it.
            try {
                $response->cancel();
            } catch (\Throwable) {
                // ignore — best-effort cleanup
            }

            usleep(1_000_000); // 1 s backoff
        }
    }

    /**
     * Whether the given Gemini model is in the "Pro" tier.
     *
     * Pro models (gemini-2.5-pro, gemini-3.x-pro, …) mandate a non-zero
     * thinking budget — sending `thinkingBudget: 0` returns HTTP 4xx (sometimes
     * surfaced as 5xx by Google's edge). We use this to skip the disable path
     * for Pro models entirely while keeping it for Flash where 0 is allowed.
     *
     * The heuristic matches `pro` only as a hyphen- or slash-delimited token
     * (or at the start/end of the string), so unrelated names that happen to
     * contain the substring (e.g. an internal alias with `prompt`/`promo`/
     * `approve` in it) don't accidentally trip the Pro guard.
     *
     * Examples that match: `gemini-2.5-pro`, `gemini-pro`, `gemini-1.5-pro-002`,
     * `models/gemini-3.0-pro-latest`.
     * Examples that don't match: `gemini-flash-promo`, `gemini-prompt-tuner`.
     */
    private function isProGeminiModel(string $model): bool
    {
        $lower = strtolower($model);

        return 1 === preg_match('/(?:^|[\/-])pro(?:[\/-]|$)/', $lower);
    }

    /**
     * Translate the cross-provider `reasoning_effort` knob into Google's
     * `generationConfig.thinkingConfig` payload.
     *
     * Returns null when no thinking config should be sent (Google falls back
     * to its server-side default). Accepts either `reasoning_effort`
     * (cross-provider) or the Google-native `thinking_budget` key.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, int|bool>|null
     */
    private function resolveThinkingConfig(array $options, string $model = ''): ?array
    {
        // Native passthrough — admin/per-prompt override wins over the
        // generic effort knob (and bypasses the Pro-tier guard, since the
        // caller is explicit about the budget).
        if (array_key_exists('thinking_budget', $options)) {
            $budget = (int) $options['thinking_budget'];

            return ['thinkingBudget' => $budget];
        }

        // Cross-provider semantic levels. Thinking off stays a zero budget
        // on Flash (and is omitted on Pro, which rejects 0). A chosen `low`
        // is a real, smaller budget — not off.
        $effort = $options['reasoning_effort'] ?? null;

        // The legacy `reasoning => true/false` flag maps to high / off so
        // callers that flip reasoning without choosing a level keep the
        // previous on/off behaviour.
        if (null === $effort && array_key_exists('reasoning', $options)) {
            $effort = ((bool) $options['reasoning']) ? 'high' : 'off';
        }

        if (null === $effort) {
            return null;
        }

        // Pro guard: gemini-*-pro models reject `thinkingBudget: 0` with HTTP
        // 4xx (Google sometimes maps this to an opaque 503). For Pro models
        // we OMIT thinkingConfig on the disable path and let Google's
        // server-side default apply — the user picked Pro, they expect
        // reasoning. Flash models accept 0 for max-speed mode.
        $isPro = '' !== $model && $this->isProGeminiModel($model);

        return match ($effort) {
            'off', 'none', 'disabled' => $isPro ? null : ['thinkingBudget' => 0],
            'low' => ['thinkingBudget' => self::LOW_THINKING_BUDGET],
            'medium' => ['thinkingBudget' => 1024],
            'high' => ['thinkingBudget' => 8192],
            'dynamic' => ['thinkingBudget' => -1],
            default => null,
        };
    }
}
