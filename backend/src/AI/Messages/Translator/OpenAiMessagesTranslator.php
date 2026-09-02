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
 * Anthropic Messages ↔ OpenAI Chat Completions translator.
 *
 * Strips Anthropic-only fields (`thinking`, beta body keys) that Claude Code
 * sends to gateway aliases. Tool schemas map `input_schema` → `parameters`;
 * image blocks map to `image_url` parts so vision survives the alias route.
 */
#[AutoconfigureTag('app.messages.translator')]
final readonly class OpenAiMessagesTranslator implements MessagesTranslatorInterface
{
    private const DEFAULT_UPSTREAM = 'https://api.openai.com';
    private const DEFAULT_TIMEOUT = 600;

    /** Anthropic-only top-level keys that OpenAI rejects. */
    private const STRIP_KEYS = [
        'thinking',
        'context_management',
        'output_config',
        'mcp_servers',
        'container',
        'anthropic_beta',
        'anthropic_version',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function supports(string $providerName): bool
    {
        return 'openai' === strtolower($providerName);
    }

    public function complete(array $requestBody, array $context): array
    {
        $payload = $this->toOpenAiRequest($requestBody, stream: false, imageDetail: $this->imageDetail($context));
        $response = $this->request($payload, $context, stream: false);
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
                'body' => $this->toAnthropicError(null, 'Invalid OpenAI response', 502),
                'usage' => new MessagesUsage(),
            ];
        }

        $anthropic = $this->fromOpenAiResponse($decoded, $requestBody);

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
        $payload = $this->toOpenAiRequest($requestBody, stream: true, imageDetail: $this->imageDetail($context));
        $response = $this->request($payload, $context, stream: true);
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

        return $this->streamOpenAiToAnthropic($response, $requestBody, $emit);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public function toOpenAiRequest(array $requestBody, bool $stream, ?string $imageDetail = null): array
    {
        foreach (self::STRIP_KEYS as $key) {
            unset($requestBody[$key]);
        }

        $messages = [];
        $system = $requestBody['system'] ?? null;
        if (\is_string($system) && '' !== $system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        } elseif (\is_array($system)) {
            $sysText = $this->flattenTextBlocks($system);
            if ('' !== $sysText) {
                $messages[] = ['role' => 'system', 'content' => $sysText];
            }
        }

        foreach ($requestBody['messages'] ?? [] as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            foreach ($this->mapAnthropicMessage($msg, $imageDetail) as $mapped) {
                $messages[] = $mapped;
            }
        }

        $payload = [
            'model' => (string) ($requestBody['model'] ?? ''),
            'messages' => $messages,
            'stream' => $stream,
        ];

        if (isset($requestBody['max_tokens'])) {
            $payload['max_tokens'] = (int) $requestBody['max_tokens'];
        }
        if (isset($requestBody['temperature'])) {
            $payload['temperature'] = $requestBody['temperature'];
        }
        if (isset($requestBody['top_p'])) {
            $payload['top_p'] = $requestBody['top_p'];
        }
        if (isset($requestBody['stop_sequences']) && \is_array($requestBody['stop_sequences'])) {
            $payload['stop'] = $requestBody['stop_sequences'];
        }

        if (isset($requestBody['tools']) && \is_array($requestBody['tools'])) {
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
            $tools = OpenAiToolShapes::toChatCompletionsTools($clientTools);
            if ([] !== $tools) {
                $payload['tools'] = $tools;
            }
        }

        if (isset($requestBody['tool_choice'])) {
            $payload['tool_choice'] = OpenAiToolShapes::mapAnthropicToolChoice($requestBody['tool_choice']);
        }

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $openai
     * @param array<string, mixed> $originalRequest
     *
     * @return array<string, mixed>
     */
    public function fromOpenAiResponse(array $openai, array $originalRequest): array
    {
        $choice = $openai['choices'][0] ?? [];
        $message = \is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = [];

        $text = $message['content'] ?? null;
        if (\is_string($text) && '' !== $text) {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($message['tool_calls'] ?? [] as $call) {
            if (!\is_array($call)) {
                continue;
            }
            $fn = \is_array($call['function'] ?? null) ? $call['function'] : [];
            $argsRaw = (string) ($fn['arguments'] ?? '{}');
            $args = json_decode($argsRaw, true);
            $content[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? uniqid('toolu_', true)),
                'name' => (string) ($fn['name'] ?? 'tool'),
                'input' => \is_array($args) ? $args : [],
            ];
        }

        $finish = (string) ($choice['finish_reason'] ?? 'stop');
        $stopReason = match ($finish) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            'content_filter' => 'end_turn',
            default => 'end_turn',
        };

        $usageIn = \is_array($openai['usage'] ?? null) ? $openai['usage'] : [];

        return [
            'id' => (string) ($openai['id'] ?? ('msg_'.bin2hex(random_bytes(8)))),
            'type' => 'message',
            'role' => 'assistant',
            'model' => (string) ($originalRequest['model'] ?? $openai['model'] ?? ''),
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => (int) ($usageIn['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usageIn['completion_tokens'] ?? 0),
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => (int) ($usageIn['prompt_tokens_details']['cached_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $msg
     *
     * @return list<array<string, mixed>>
     */
    private function mapAnthropicMessage(array $msg, ?string $imageDetail = null): array
    {
        $role = (string) ($msg['role'] ?? 'user');
        $content = $msg['content'] ?? '';

        if (\is_string($content)) {
            return [['role' => $role, 'content' => $content]];
        }

        if (!\is_array($content)) {
            return [];
        }

        $out = [];
        $textParts = [];
        $mediaParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');

            if ('text' === $type) {
                $textParts[] = (string) ($block['text'] ?? '');
            } elseif ('image' === $type) {
                $url = $this->imageBlockToUrl($block);
                if (null !== $url) {
                    $imageUrl = ['url' => $url];
                    // `auto` is the provider's own default — leaving the field
                    // out keeps the payload compatible with the stricter
                    // OpenAI-compatible upstreams.
                    if (null !== $imageDetail && 'auto' !== $imageDetail) {
                        $imageUrl['detail'] = $imageDetail;
                    }
                    $mediaParts[] = ['type' => 'image_url', 'image_url' => $imageUrl];
                }
            } elseif ('tool_use' === $type) {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? uniqid('call_', true)),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? 'tool'),
                        'arguments' => json_encode($block['input'] ?? [], \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE),
                    ],
                ];
            } elseif ('tool_result' === $type) {
                $resultContent = $block['content'] ?? '';
                if (\is_array($resultContent)) {
                    $resultContent = $this->flattenTextBlocks($resultContent);
                }
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                    'content' => (string) $resultContent,
                ];
            }
        }

        if ('assistant' === $role && ([] !== $textParts || [] !== $toolCalls)) {
            $assistant = ['role' => 'assistant', 'content' => [] !== $textParts ? implode("\n", $textParts) : ''];
            if ([] !== $toolCalls) {
                $assistant['tool_calls'] = $toolCalls;
            }
            array_unshift($out, $assistant);
        } elseif ('user' === $role && ([] !== $textParts || [] !== $mediaParts)) {
            if ([] === $mediaParts) {
                array_unshift($out, ['role' => 'user', 'content' => implode("\n", $textParts)]);
            } else {
                // Multimodal turns must stay a content-part array; collapsing
                // them to a string would drop the attachment.
                $parts = [];
                if ([] !== $textParts) {
                    $parts[] = ['type' => 'text', 'text' => implode("\n", $textParts)];
                }
                array_unshift($out, ['role' => 'user', 'content' => array_merge($parts, $mediaParts)]);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function imageDetail(array $context): ?string
    {
        $detail = $context['image_detail'] ?? null;

        return \is_string($detail) && '' !== $detail ? $detail : null;
    }

    /**
     * Anthropic `image` block → OpenAI `image_url` value.
     *
     * Base64 sources become a data URL; URL sources pass through. Anything the
     * shape does not cover returns null so the turn is still sent without it,
     * rather than failing the whole request.
     *
     * @param array<string, mixed> $block
     */
    private function imageBlockToUrl(array $block): ?string
    {
        $source = $block['source'] ?? null;
        if (!\is_array($source)) {
            return null;
        }

        $sourceType = (string) ($source['type'] ?? '');

        if ('base64' === $sourceType) {
            $data = $source['data'] ?? null;
            if (!\is_string($data) || '' === $data) {
                return null;
            }
            $mediaType = \is_string($source['media_type'] ?? null) ? $source['media_type'] : 'image/png';

            return 'data:'.$mediaType.';base64,'.$data;
        }

        if ('url' === $sourceType && \is_string($source['url'] ?? null) && '' !== $source['url']) {
            return $source['url'];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $blocks
     */
    private function flattenTextBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            } elseif (\is_string($block)) {
                $parts[] = $block;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function request(array $payload, array $context, bool $stream): ResponseInterface
    {
        $upstream = rtrim((string) ($context['openai_upstream_url'] ?? self::DEFAULT_UPSTREAM), '/');
        $url = $upstream.'/v1/chat/completions';

        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'authorization' => 'Bearer '.$context['api_key'],
                'content-type' => 'application/json',
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
        $message = 'OpenAI upstream error';
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
            'error' => [
                'type' => $type,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param array<string, mixed>                                                    $requestBody
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    private function streamOpenAiToAnthropic(ResponseInterface $response, array $requestBody, callable $emit): MessagesUsage
    {
        $msgId = 'msg_'.bin2hex(random_bytes(8));
        $model = (string) ($requestBody['model'] ?? '');
        $emit([
            'event' => 'message_start',
            'data' => [
                'type' => 'message_start',
                'message' => [
                    'id' => $msgId,
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => $model,
                    'content' => [],
                    'stop_reason' => null,
                    'stop_sequence' => null,
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                ],
            ],
        ]);

        $textStarted = false;
        $textIndex = 0;
        /** @var array<int, array{id: string, name: string, arguments: string, index: int}> $toolState */
        $toolState = [];
        $nextIndex = 0;
        $stopReason = 'end_turn';
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheRead = 0;
        $buffer = '';

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
                if ('[DONE]' === $payload) {
                    continue;
                }
                $decoded = json_decode($payload, true);
                if (!\is_array($decoded)) {
                    continue;
                }

                if (isset($decoded['usage']) && \is_array($decoded['usage'])) {
                    $inputTokens = (int) ($decoded['usage']['prompt_tokens'] ?? $inputTokens);
                    $outputTokens = (int) ($decoded['usage']['completion_tokens'] ?? $outputTokens);
                    $cacheRead = (int) ($decoded['usage']['prompt_tokens_details']['cached_tokens'] ?? $cacheRead);
                }

                $choice = $decoded['choices'][0] ?? null;
                if (!\is_array($choice)) {
                    continue;
                }
                $delta = \is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
                $finish = $choice['finish_reason'] ?? null;

                if (isset($delta['content']) && \is_string($delta['content']) && '' !== $delta['content']) {
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
                            'delta' => ['type' => 'text_delta', 'text' => $delta['content']],
                        ],
                    ]);
                }

                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    if (!\is_array($tc)) {
                        continue;
                    }
                    $tcIndex = (int) ($tc['index'] ?? 0);
                    if (!isset($toolState[$tcIndex])) {
                        $blockIndex = $nextIndex++;
                        $id = (string) ($tc['id'] ?? ('toolu_'.$tcIndex));
                        $name = (string) ($tc['function']['name'] ?? 'tool');
                        $toolState[$tcIndex] = [
                            'id' => $id,
                            'name' => $name,
                            'arguments' => '',
                            'index' => $blockIndex,
                        ];
                        $emit([
                            'event' => 'content_block_start',
                            'data' => [
                                'type' => 'content_block_start',
                                'index' => $blockIndex,
                                'content_block' => [
                                    'type' => 'tool_use',
                                    'id' => $id,
                                    'name' => $name,
                                    'input' => [],
                                ],
                            ],
                        ]);
                    }
                    if (isset($tc['id']) && \is_string($tc['id'])) {
                        $toolState[$tcIndex]['id'] = $tc['id'];
                    }
                    if (isset($tc['function']['name']) && \is_string($tc['function']['name'])) {
                        $toolState[$tcIndex]['name'] = $tc['function']['name'];
                    }
                    $argDelta = (string) ($tc['function']['arguments'] ?? '');
                    if ('' !== $argDelta) {
                        $toolState[$tcIndex]['arguments'] .= $argDelta;
                        $emit([
                            'event' => 'content_block_delta',
                            'data' => [
                                'type' => 'content_block_delta',
                                'index' => $toolState[$tcIndex]['index'],
                                'delta' => ['type' => 'input_json_delta', 'partial_json' => $argDelta],
                            ],
                        ]);
                    }
                }

                if (\is_string($finish) && '' !== $finish) {
                    $stopReason = match ($finish) {
                        'tool_calls' => 'tool_use',
                        'length' => 'max_tokens',
                        default => 'end_turn',
                    };
                }
            }
        }

        if ($textStarted) {
            $emit([
                'event' => 'content_block_stop',
                'data' => ['type' => 'content_block_stop', 'index' => $textIndex],
            ]);
        }
        foreach ($toolState as $state) {
            $emit([
                'event' => 'content_block_stop',
                'data' => ['type' => 'content_block_stop', 'index' => $state['index']],
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

        // Patch input tokens onto a synthetic message_start is too late; return usage for metering.
        return new MessagesUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationTokens: 0,
            cacheReadTokens: $cacheRead,
            stopReason: $stopReason,
        );
    }
}
