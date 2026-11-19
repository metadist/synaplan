<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\MessagesEventEmitter;
use App\AI\Messages\MessagesUsage;

/**
 * Streams one upstream Messages turn. Content blocks are relayed live (our
 * tool_use blocks suppressed). message_delta / message_stop are held until
 * stop_reason is known so intermediate tool rounds stay invisible. One
 * instance per turn.
 *
 * @phpstan-type StreamedTurn array{
 *     content: list<array<string, mixed>>,
 *     stop_reason: string|null,
 *     usage: MessagesUsage,
 *     error: bool,
 *     held_stop: array{event: string, data: array<string, mixed>}|null
 * }
 * @phpstan-type StreamCall \Closure(array<string, mixed>, callable(string|array{event: string, data: array<string, mixed>}): void): mixed
 */
final class MessagesTurnCollector
{
    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $tail = [];
    /** @var array<int, array<string, mixed>> */
    private array $blocksByIndex = [];
    private ?string $stopReason = null;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheCreation = 0;
    private int $cacheCreation1h = 0;
    private int $cacheRead = 0;
    private bool $error = false;

    /**
     * @param list<string> $suppressNames
     * @param bool         $relayErrors   when false, an upstream error is recorded but not streamed so a wrap-up recover can still emit a single terminal answer
     */
    public function __construct(
        private readonly MessagesEventEmitter $emitter,
        private readonly array $suppressNames,
        private readonly bool $relayErrors = true,
    ) {
    }

    /**
     * @param StreamCall                                 $stream      one streaming upstream model call
     * @param array<string, mixed>                       $requestBody
     * @param \Closure(list<array<string, mixed>>): bool $hasOurs     whether the turn holds a tool call Synaplan runs
     *
     * @return StreamedTurn
     */
    public function collect(\Closure $stream, array $requestBody, \Closure $hasOurs): array
    {
        $streamBody = $requestBody;
        $streamBody['stream'] = true;
        $stream($streamBody, function (string|array $chunk): void {
            $this->onChunk($chunk);
        });

        ksort($this->blocksByIndex, \SORT_NUMERIC);
        $content = [];
        foreach ($this->blocksByIndex as $block) {
            unset($block['_partial_json']);
            $content[] = $block;
        }

        $isFinal = 'tool_use' !== $this->stopReason || $this->error || !$hasOurs($content);

        return [
            'content' => $content,
            'stop_reason' => $this->stopReason,
            'usage' => new MessagesUsage(
                inputTokens: $this->inputTokens,
                outputTokens: $this->outputTokens,
                cacheCreationTokens: $this->cacheCreation,
                cacheCreation1hTokens: $this->cacheCreation1h,
                cacheReadTokens: $this->cacheRead,
                stopReason: $this->stopReason,
            ),
            'error' => $this->error,
            'held_stop' => $this->relayTail($isFinal),
        ];
    }

    /**
     * @param string|array{event: string, data: array<string, mixed>} $chunk
     */
    private function onChunk(string|array $chunk): void
    {
        if (\is_string($chunk)) {
            $this->error = true;

            return;
        }

        $event = (string) $chunk['event'];
        $data = $chunk['data'];
        $type = (string) ($data['type'] ?? $event);

        if ('error' === $type) {
            $this->error = true;
            if ($this->relayErrors) {
                $this->emitter->relay($event, $data, isFinalTurn: true, suppressToolNames: []);
            }

            return;
        }
        if ('message_start' === $type) {
            $this->onMessageStart($data);
        } elseif (str_starts_with($type, 'content_block_')) {
            $this->onContentBlock($type, $data);
        } elseif ('message_delta' === $type) {
            $this->onMessageDelta($data);
            $this->tail[] = ['event' => $event, 'data' => $data];

            return;
        } elseif ('message_stop' === $type) {
            $this->tail[] = ['event' => $event, 'data' => $data];

            return;
        }

        // message_start is emitted at once and suppressed on later turns by the
        // emitter. Server tools are suppressed live. Client tools stay held in
        // the emitter until message_delta, so a mixed turn can drop them and
        // read the page before the client sees a write.
        $this->emitter->relay($event, $data, isFinalTurn: false, suppressToolNames: $this->suppressNames);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function onMessageStart(array $data): void
    {
        $usage = $data['message']['usage'] ?? [];
        if (!\is_array($usage)) {
            return;
        }
        $this->inputTokens = (int) ($usage['input_tokens'] ?? $this->inputTokens);
        $this->cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? $this->cacheCreation);
        $this->cacheCreation1h = MessagesUsage::extractCacheCreation1hTokens($usage);
        $this->cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $this->cacheRead);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function onContentBlock(string $type, array $data): void
    {
        $idx = (int) ($data['index'] ?? 0);
        if ('content_block_start' === $type) {
            $block = $data['content_block'] ?? [];
            if (\is_array($block)) {
                $this->blocksByIndex[$idx] = $block;
                if ('tool_use' === ($block['type'] ?? '')) {
                    $this->blocksByIndex[$idx]['input'] ??= [];
                    $this->blocksByIndex[$idx]['_partial_json'] = '';
                }
            }
        }

        if ('content_block_delta' === $type) {
            $delta = $data['delta'] ?? [];
            if (\is_array($delta) && isset($this->blocksByIndex[$idx])) {
                if ('text_delta' === ($delta['type'] ?? '') && isset($delta['text'])) {
                    $this->blocksByIndex[$idx]['text'] = ((string) ($this->blocksByIndex[$idx]['text'] ?? '')).(string) $delta['text'];
                }
                if ('input_json_delta' === ($delta['type'] ?? '') && isset($delta['partial_json'])) {
                    $this->blocksByIndex[$idx]['_partial_json'] = ((string) ($this->blocksByIndex[$idx]['_partial_json'] ?? '')).(string) $delta['partial_json'];
                }
            }
        }

        if ('content_block_stop' === $type && isset($this->blocksByIndex[$idx]['_partial_json'])) {
            $raw = (string) $this->blocksByIndex[$idx]['_partial_json'];
            unset($this->blocksByIndex[$idx]['_partial_json']);
            if ('' !== $raw) {
                $decoded = json_decode($raw, true);
                $this->blocksByIndex[$idx]['input'] = \is_array($decoded) ? $decoded : [];
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function onMessageDelta(array $data): void
    {
        $usage = $data['usage'] ?? [];
        if (\is_array($usage)) {
            $this->outputTokens = (int) ($usage['output_tokens'] ?? $this->outputTokens);
        }
        $delta = $data['delta'] ?? [];
        if (\is_array($delta) && isset($delta['stop_reason']) && \is_string($delta['stop_reason'])) {
            $this->stopReason = $delta['stop_reason'];
        }
    }

    /**
     * Relays the held message_delta and keeps message_stop for the caller.
     *
     * @return array{event: string, data: array<string, mixed>}|null
     */
    private function relayTail(bool $isFinal): ?array
    {
        $heldStop = null;
        foreach ($this->tail as $item) {
            $type = (string) ($item['data']['type'] ?? $item['event']);
            if ('message_stop' === $type) {
                $heldStop = $item;
                continue;
            }
            $this->emitter->relay(
                $item['event'],
                $item['data'],
                isFinalTurn: $isFinal,
                suppressToolNames: $this->suppressNames,
            );
        }

        return $heldStop;
    }
}
