<?php

declare(strict_types=1);

namespace App\AI\Stream;

use App\AI\Messages\MessagesUsage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Anthropic SSE decoder. The provider loop feeds the raw body and forwards
 * each chunk to its existing callback.
 */
final class AnthropicStreamDecoder implements StreamDecoderInterface
{
    private string $buffer = '';
    private ?string $blockType = null;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheCreationTokens = 0;
    private int $cacheCreation1hTokens = 0;
    private int $cacheReadTokens = 0;
    private ?string $finishReason = null;

    public function __construct(
        private readonly bool $toolArgumentsAreCalls,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $chunks = [];
        while (false !== ($pos = strpos($this->buffer, "\n\n"))) {
            $eventData = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            $event = $this->parseEvent($eventData);
            if (null === $event) {
                continue;
            }
            array_push($chunks, ...$this->onEvent($event));
        }

        return $chunks;
    }

    public function finish(): array
    {
        if (null === $this->finishReason) {
            return [];
        }
        $reason = $this->finishReason;
        $this->finishReason = null;

        return [new DecodedEvent('callback', ['type' => 'finish', 'finish_reason' => $reason])];
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int, cache_creation_1h_tokens: int}
     */
    public function usage(): array
    {
        return [
            'prompt_tokens' => $this->inputTokens + $this->cacheCreationTokens + $this->cacheReadTokens,
            'completion_tokens' => $this->outputTokens,
            'total_tokens' => $this->inputTokens + $this->outputTokens + $this->cacheCreationTokens + $this->cacheReadTokens,
            'cached_tokens' => $this->cacheReadTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
            'cache_creation_1h_tokens' => $this->cacheCreation1hTokens,
        ];
    }

    /**
     * @param array{type: string, data: mixed} $event
     *
     * @return list<DecodedEvent>
     */
    private function onEvent(array $event): array
    {
        $chunks = [];
        switch ($event['type']) {
            case 'message_start':
                $msgUsage = is_array($event['data']['message']['usage'] ?? null) ? $event['data']['message']['usage'] : [];
                $this->inputTokens = $msgUsage['input_tokens'] ?? 0;
                $this->cacheCreationTokens = $msgUsage['cache_creation_input_tokens'] ?? 0;
                $this->cacheCreation1hTokens = MessagesUsage::extractCacheCreation1hTokens($msgUsage);
                $this->cacheReadTokens = $msgUsage['cache_read_input_tokens'] ?? 0;
                break;
            case 'content_block_start':
                $this->blockType = $event['data']['content_block']['type'] ?? null;
                if ('thinking' === $this->blockType) {
                    $this->logger->info('🧠 Anthropic: Thinking block started');
                } elseif ('tool_use' === $this->blockType) {
                    $block = is_array($event['data']['content_block'] ?? null) ? $event['data']['content_block'] : [];
                    $chunks[] = new DecodedEvent('callback', [
                        'type' => 'tool_call_delta',
                        'index' => (int) ($event['data']['index'] ?? 0),
                        'id' => isset($block['id']) && is_string($block['id']) && '' !== $block['id'] ? $block['id'] : null,
                        'name' => isset($block['name']) && is_string($block['name']) && '' !== $block['name'] ? $block['name'] : null,
                        'arguments' => '',
                    ]);
                }
                break;
            case 'content_block_delta':
                $delta = is_array($event['data']['delta'] ?? null) ? $event['data']['delta'] : [];
                $deltaType = $delta['type'] ?? '';
                if ('text_delta' === $deltaType) {
                    $chunks[] = new DecodedEvent('callback', ['type' => 'content', 'content' => $delta['text'] ?? '']);
                } elseif ('thinking_delta' === $deltaType) {
                    $chunks[] = new DecodedEvent('callback', ['type' => 'reasoning', 'content' => $delta['thinking'] ?? '']);
                } elseif ('input_json_delta' === $deltaType && $this->toolArgumentsAreCalls) {
                    $chunks[] = new DecodedEvent('callback', [
                        'type' => 'tool_call_delta',
                        'index' => (int) ($event['data']['index'] ?? 0),
                        'id' => null,
                        'name' => null,
                        'arguments' => is_string($delta['partial_json'] ?? null) ? $delta['partial_json'] : '',
                    ]);
                } elseif ('input_json_delta' === $deltaType) {
                    $chunks[] = new DecodedEvent('callback', ['type' => 'content', 'content' => $delta['partial_json'] ?? '']);
                }
                break;
            case 'content_block_stop':
                if ('thinking' === $this->blockType) {
                    $this->logger->info('🧠 Anthropic: Thinking block completed');
                }
                $this->blockType = null;
                break;
            case 'message_delta':
                $deltaUsage = is_array($event['data']['usage'] ?? null) ? $event['data']['usage'] : [];
                $this->outputTokens = $deltaUsage['output_tokens'] ?? $this->outputTokens;
                $stopReason = $event['data']['delta']['stop_reason'] ?? null;
                if (null !== $stopReason) {
                    $this->finishReason = match ($stopReason) {
                        'max_tokens' => 'length',
                        'end_turn' => 'stop',
                        'tool_use' => 'tool_calls',
                        default => $stopReason,
                    };
                }
                break;
            case 'error':
                $errorMessage = $event['data']['error']['message'] ?? 'Unknown error';
                throw new \Exception($errorMessage);
            case 'message_stop':
            case 'ping':
                break;
        }

        return $chunks;
    }

    /**
     * @return array{type: string, data: mixed}|null
     */
    private function parseEvent(string $eventData): ?array
    {
        $event = ['type' => null, 'data' => null];
        foreach (explode("\n", $eventData) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'event:')) {
                $event['type'] = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $jsonData = trim(substr($line, 5));
                if ('' === $jsonData) {
                    continue;
                }
                $decoded = json_decode($jsonData, true);
                if (null === $decoded) {
                    continue;
                }
                if (!$event['type'] && isset($decoded['type'])) {
                    $event['type'] = $decoded['type'];
                }
                $event['data'] = $decoded;
            }
        }

        return is_string($event['type']) && '' !== $event['type'] ? ['type' => $event['type'], 'data' => $event['data']] : null;
    }
}
