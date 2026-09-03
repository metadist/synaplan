<?php

declare(strict_types=1);

namespace App\AI\Messages\Translator;

use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\AnthropicServerTools;
use App\AI\Tool\OpenAiToolShapes;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Anthropic Messages ↔ Gemini generateContent translator.
 *
 * Uses `parametersJsonSchema` for tool declarations (Gemini 2026 surface) so
 * MCP `input_schema` passes through with light guardrails. Strips Anthropic
 * `thinking` / beta body fields that Claude Code sends to aliases. Image
 * blocks map to `inlineData` / `fileData` so vision survives the alias route.
 */
#[AutoconfigureTag('app.messages.translator')]
final readonly class GeminiMessagesTranslator implements MessagesTranslatorInterface
{
    private const DEFAULT_UPSTREAM = 'https://generativelanguage.googleapis.com';
    private const API_VERSION = 'v1beta';
    private const DEFAULT_TIMEOUT = 600;

    private const STRIP_KEYS = [
        'thinking',
        'context_management',
        'output_config',
        'mcp_servers',
        'container',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function supports(string $providerName): bool
    {
        $p = strtolower($providerName);

        return 'google' === $p || 'gemini' === $p;
    }

    public function complete(array $requestBody, array $context): array
    {
        $payload = $this->toGeminiRequest($requestBody);
        $response = $this->request($payload, $context, $requestBody, stream: false);
        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $raw = $response->getContent(false);
        $decoded = json_decode($raw, true);

        if ($status >= 400) {
            return [
                'status' => $status,
                'headers' => $headers,
                'body' => $this->toAnthropicError(\is_array($decoded) ? $decoded : null, $raw, $status),
                'usage' => new MessagesUsage(),
            ];
        }

        if (!\is_array($decoded)) {
            return [
                'status' => 502,
                'headers' => $headers,
                'body' => $this->toAnthropicError(null, 'Invalid Gemini response', 502),
                'usage' => new MessagesUsage(),
            ];
        }

        $anthropic = $this->fromGeminiResponse($decoded, $requestBody);

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => $anthropic,
            'usage' => MessagesUsage::fromAnthropicUsage(
                $anthropic['usage'] ?? [],
                \is_string($anthropic['stop_reason'] ?? null) ? $anthropic['stop_reason'] : null,
            ),
        ];
    }

    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage
    {
        $payload = $this->toGeminiRequest($requestBody);
        $response = $this->request($payload, $context, $requestBody, stream: true);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $raw = $response->getContent(false);
            $decoded = json_decode($raw, true);
            $emit([
                'event' => 'error',
                'data' => $this->toAnthropicError(\is_array($decoded) ? $decoded : null, $raw, $status),
            ]);

            return new MessagesUsage();
        }

        return $this->streamGeminiToAnthropic($response, $requestBody, $emit);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public function toGeminiRequest(array $requestBody): array
    {
        foreach (self::STRIP_KEYS as $key) {
            unset($requestBody[$key]);
        }

        $systemInstruction = null;
        $system = $requestBody['system'] ?? null;
        if (\is_string($system) && '' !== $system) {
            $systemInstruction = ['parts' => [['text' => $system]]];
        } elseif (\is_array($system)) {
            $text = $this->flattenText($system);
            if ('' !== $text) {
                $systemInstruction = ['parts' => [['text' => $text]]];
            }
        }

        $toolNamesById = $this->indexToolUseNames($requestBody['messages'] ?? []);
        $contents = [];
        foreach ($requestBody['messages'] ?? [] as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            $mapped = $this->mapMessage($msg, $toolNamesById);
            if (null !== $mapped) {
                $contents[] = $mapped;
            }
        }

        $payload = ['contents' => $contents];
        if (null !== $systemInstruction) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $generationConfig = [];
        if (isset($requestBody['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $requestBody['max_tokens'];
        }
        if (isset($requestBody['temperature'])) {
            $generationConfig['temperature'] = (float) $requestBody['temperature'];
        }
        if (isset($requestBody['top_p'])) {
            $generationConfig['topP'] = (float) $requestBody['top_p'];
        }
        if ([] !== $generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        if (isset($requestBody['tools']) && \is_array($requestBody['tools']) && [] !== $requestBody['tools']) {
            $clientTools = [];
            foreach ($requestBody['tools'] as $tool) {
                if (!\is_array($tool) || AnthropicServerTools::isServerToolDeclaration($tool)) {
                    // Server tools (`web_search_*`, `code_execution_*`, …) are
                    // executed by the API side, not by the model, and carry no
                    // input schema. Mapping one to a function declaration would
                    // hand the model a tool nobody can answer.
                    continue;
                }
                $clientTools[] = $tool;
            }
            $declarations = OpenAiToolShapes::toGeminiDeclarations($clientTools);
            if ([] !== $declarations) {
                $payload['tools'] = [['functionDeclarations' => $declarations]];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $gemini
     * @param array<string, mixed> $originalRequest
     *
     * @return array<string, mixed>
     */
    public function fromGeminiResponse(array $gemini, array $originalRequest): array
    {
        $candidate = $gemini['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $content = [];
        if (\is_array($parts)) {
            foreach ($parts as $i => $part) {
                if (!\is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && \is_string($part['text'])) {
                    $content[] = ['type' => 'text', 'text' => $part['text']];
                }
                if (isset($part['functionCall']) && \is_array($part['functionCall'])) {
                    $fc = $part['functionCall'];
                    $args = $fc['args'] ?? [];
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => 'toolu_gemini_'.$i,
                        'name' => (string) ($fc['name'] ?? 'tool'),
                        'input' => \is_array($args) ? $args : [],
                    ];
                }
            }
        }

        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        $hasTools = false;
        foreach ($content as $block) {
            if ('tool_use' === $block['type']) {
                $hasTools = true;
                break;
            }
        }
        $stopReason = match (true) {
            $hasTools => 'tool_use',
            'MAX_TOKENS' === $finish => 'max_tokens',
            default => 'end_turn',
        };

        $usageMeta = \is_array($gemini['usageMetadata'] ?? null) ? $gemini['usageMetadata'] : [];

        return [
            'id' => 'msg_'.bin2hex(random_bytes(8)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => (string) ($originalRequest['model'] ?? ''),
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => (int) ($usageMeta['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($usageMeta['candidatesTokenCount'] ?? 0),
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => (int) ($usageMeta['cachedContentTokenCount'] ?? 0),
            ],
        ];
    }

    /**
     * @param list<mixed> $messages
     *
     * @return array<string, string> tool_use id → name
     */
    private function indexToolUseNames(array $messages): array
    {
        $map = [];
        foreach ($messages as $msg) {
            if (!\is_array($msg) || !\is_array($msg['content'] ?? null)) {
                continue;
            }
            foreach ($msg['content'] as $block) {
                if (\is_array($block) && 'tool_use' === ($block['type'] ?? '') && isset($block['id'], $block['name'])) {
                    $map[(string) $block['id']] = (string) $block['name'];
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed>  $msg
     * @param array<string, string> $toolNamesById
     *
     * @return array{role: string, parts: list<array<string, mixed>>}|null
     */
    private function mapMessage(array $msg, array $toolNamesById = []): ?array
    {
        $role = (string) ($msg['role'] ?? 'user');
        $geminiRole = 'assistant' === $role ? 'model' : 'user';
        $content = $msg['content'] ?? '';
        $parts = [];

        if (\is_string($content)) {
            $parts[] = ['text' => $content];
        } elseif (\is_array($content)) {
            foreach ($content as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if ('text' === $type) {
                    $parts[] = ['text' => (string) ($block['text'] ?? '')];
                } elseif ('image' === $type) {
                    $part = $this->imageBlockToPart($block);
                    if (null !== $part) {
                        $parts[] = $part;
                    }
                } elseif ('tool_use' === $type) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => (string) ($block['name'] ?? 'tool'),
                            'args' => \is_array($block['input'] ?? null) ? $block['input'] : new \stdClass(),
                        ],
                    ];
                    $geminiRole = 'model';
                } elseif ('tool_result' === $type) {
                    $result = $block['content'] ?? '';
                    if (\is_array($result)) {
                        $result = $this->flattenText($result);
                    }
                    $toolUseId = (string) ($block['tool_use_id'] ?? '');
                    $parts[] = [
                        'functionResponse' => [
                            'name' => $toolNamesById[$toolUseId] ?? $toolUseId ?: 'tool',
                            'response' => ['content' => (string) $result],
                        ],
                    ];
                    $geminiRole = 'user';
                }
            }
        }

        if ([] === $parts) {
            return null;
        }

        return ['role' => $geminiRole, 'parts' => $parts];
    }

    /**
     * Anthropic `image` block → Gemini part.
     *
     * Base64 sources become `inlineData`, URL sources `fileData`. Anything the
     * shape does not cover returns null so the turn is still sent without it,
     * rather than failing the whole request.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private function imageBlockToPart(array $block): ?array
    {
        $source = $block['source'] ?? null;
        if (!\is_array($source)) {
            return null;
        }

        $sourceType = (string) ($source['type'] ?? '');
        $mimeType = \is_string($source['media_type'] ?? null) ? $source['media_type'] : 'image/png';

        if ('base64' === $sourceType) {
            $data = $source['data'] ?? null;
            if (!\is_string($data) || '' === $data) {
                return null;
            }

            return ['inlineData' => ['mimeType' => $mimeType, 'data' => $data]];
        }

        if ('url' === $sourceType && \is_string($source['url'] ?? null) && '' !== $source['url']) {
            return ['fileData' => ['mimeType' => $mimeType, 'fileUri' => $source['url']]];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $blocks
     */
    private function flattenText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? '')) {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, mixed> $requestBody
     */
    private function request(array $payload, array $context, array $requestBody, bool $stream): ResponseInterface
    {
        $upstream = rtrim((string) ($context['gemini_upstream_url'] ?? self::DEFAULT_UPSTREAM), '/');
        $model = rawurlencode((string) ($requestBody['model'] ?? 'gemini-2.0-flash'));
        $method = $stream ? 'streamGenerateContent' : 'generateContent';
        $url = sprintf('%s/%s/models/%s:%s', $upstream, self::API_VERSION, $model, $method);
        if ($stream) {
            $url .= '?alt=sse';
        }

        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'content-type' => 'application/json',
                'x-goog-api-key' => $context['api_key'],
            ],
            'json' => $payload,
            'timeout' => self::DEFAULT_TIMEOUT,
            'buffer' => !$stream,
        ]);
    }

    /**
     * @param array<string, mixed>|null $decoded
     *
     * @return array<string, mixed>
     */
    private function toAnthropicError(?array $decoded, string $raw, int $status): array
    {
        $message = 'Gemini upstream error';
        if (\is_array($decoded)) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $message);
        } elseif ('' !== $raw) {
            $message = $raw;
        }

        $type = match (true) {
            401 === $status, 403 === $status => 'authentication_error',
            404 === $status => 'not_found_error',
            429 === $status => 'rate_limit_error',
            default => 'api_error',
        };

        return [
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ];
    }

    /**
     * @param array<string, mixed>                                                    $requestBody
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    private function streamGeminiToAnthropic(ResponseInterface $response, array $requestBody, callable $emit): MessagesUsage
    {
        $msgId = 'msg_'.bin2hex(random_bytes(8));
        $emit([
            'event' => 'message_start',
            'data' => [
                'type' => 'message_start',
                'message' => [
                    'id' => $msgId,
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => (string) ($requestBody['model'] ?? ''),
                    'content' => [],
                    'stop_reason' => null,
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                ],
            ],
        ]);

        $textStarted = false;
        $textIndex = 0;
        $nextIndex = 0;
        $stopReason = 'end_turn';
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheRead = 0;
        $buffer = '';
        $toolIndex = 0;

        foreach ($this->httpClient->stream($response) as $chunk) {
            set_time_limit(0);
            if ($chunk->isTimeout()) {
                continue;
            }
            $buffer .= $chunk->getContent();
            while (false !== ($pos = strpos($buffer, "\n"))) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ('' === $line || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = trim(substr($line, 5));
                $decoded = json_decode($payload, true);
                if (!\is_array($decoded)) {
                    continue;
                }

                if (isset($decoded['usageMetadata']) && \is_array($decoded['usageMetadata'])) {
                    $inputTokens = (int) ($decoded['usageMetadata']['promptTokenCount'] ?? $inputTokens);
                    $outputTokens = (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? $outputTokens);
                    $cacheRead = (int) ($decoded['usageMetadata']['cachedContentTokenCount'] ?? $cacheRead);
                }

                $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
                if (!\is_array($parts)) {
                    continue;
                }
                foreach ($parts as $part) {
                    if (!\is_array($part)) {
                        continue;
                    }
                    if (isset($part['text']) && \is_string($part['text']) && '' !== $part['text']) {
                        if (!$textStarted) {
                            $textIndex = $nextIndex++;
                            $emit([
                                'event' => 'content_block_start',
                                'data' => [
                                    'type' => 'content_block_start',
                                    'index' => $textIndex,
                                    'content_block' => ['type' => 'text', 'text' => ''],
                                ],
                            ]);
                            $textStarted = true;
                        }
                        $emit([
                            'event' => 'content_block_delta',
                            'data' => [
                                'type' => 'content_block_delta',
                                'index' => $textIndex,
                                'delta' => ['type' => 'text_delta', 'text' => $part['text']],
                            ],
                        ]);
                    }
                    if (isset($part['functionCall']) && \is_array($part['functionCall'])) {
                        $stopReason = 'tool_use';
                        $idx = $nextIndex++;
                        $fc = $part['functionCall'];
                        $args = $fc['args'] ?? [];
                        $json = json_encode(\is_array($args) ? $args : [], \JSON_THROW_ON_ERROR);
                        $emit([
                            'event' => 'content_block_start',
                            'data' => [
                                'type' => 'content_block_start',
                                'index' => $idx,
                                'content_block' => [
                                    'type' => 'tool_use',
                                    'id' => 'toolu_gemini_'.$toolIndex++,
                                    'name' => (string) ($fc['name'] ?? 'tool'),
                                    'input' => [],
                                ],
                            ],
                        ]);
                        $emit([
                            'event' => 'content_block_delta',
                            'data' => [
                                'type' => 'content_block_delta',
                                'index' => $idx,
                                'delta' => ['type' => 'input_json_delta', 'partial_json' => $json],
                            ],
                        ]);
                        $emit([
                            'event' => 'content_block_stop',
                            'data' => ['type' => 'content_block_stop', 'index' => $idx],
                        ]);
                    }
                }

                $finish = $decoded['candidates'][0]['finishReason'] ?? null;
                if ('MAX_TOKENS' === $finish) {
                    $stopReason = 'max_tokens';
                }
            }
        }

        if ($textStarted) {
            $emit([
                'event' => 'content_block_stop',
                'data' => ['type' => 'content_block_stop', 'index' => $textIndex],
            ]);
        }

        $emit([
            'event' => 'message_delta',
            'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
                'usage' => ['output_tokens' => $outputTokens],
            ],
        ]);
        $emit([
            'event' => 'message_stop',
            'data' => ['type' => 'message_stop'],
        ]);

        return new MessagesUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationTokens: 0,
            cacheReadTokens: $cacheRead,
            stopReason: $stopReason,
        );
    }
}
