<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Synthesizes one client-visible Anthropic SSE message across N upstream turns
 * (MCP tool loop). Remaps content_block indices into a monotonic space and
 * suppresses intermediate message_start / message_delta / message_stop so the
 * client sees a single logical message.
 *
 * @phpstan-type EmitFn callable(string|array{event: string, data: array<string, mixed>}): void
 */
final class MessagesEventEmitter
{
    private bool $messageStarted = false;
    private int $nextBlockIndex = 0;
    /** @var array<int, int> upstream index → client index for the current turn */
    private array $indexMap = [];
    private bool $closed = false;

    /**
     * @param EmitFn $emit
     */
    public function __construct(
        private readonly mixed $emit,
    ) {
    }

    public function resetTurnMapping(): void
    {
        $this->indexMap = [];
    }

    /**
     * Relay a parsed upstream SSE event. Returns whether the event was emitted.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $suppressToolNames tool_use names hidden from the client (our MCP tools)
     */
    public function relay(string $event, array $data, bool $isFinalTurn, array $suppressToolNames = []): bool
    {
        if ($this->closed) {
            return false;
        }

        $type = (string) ($data['type'] ?? $event);

        if ('message_start' === $type) {
            if ($this->messageStarted) {
                return false;
            }
            $this->messageStarted = true;
            $this->emitEvent($event, $data);

            return true;
        }

        if ('ping' === $type) {
            $this->emitEvent('ping', $data);

            return true;
        }

        if (str_starts_with($type, 'content_block_')) {
            return $this->relayContentBlock($event, $data, $suppressToolNames);
        }

        if ('message_delta' === $type || 'message_stop' === $type) {
            if (!$isFinalTurn) {
                return false;
            }
            $this->emitEvent($event, $data);
            if ('message_stop' === $type) {
                $this->closed = true;
            }

            return true;
        }

        if ('error' === $type) {
            $this->emitEvent($event, $data);

            return true;
        }

        // Unknown event types: forward on the final turn only to avoid
        // confusing the client mid-loop; always forward errors/pings above.
        if ($isFinalTurn) {
            $this->emitEvent($event, $data);

            return true;
        }

        return false;
    }

    public function emitPing(): void
    {
        if ($this->closed) {
            return;
        }

        $this->emitEvent('ping', ['type' => 'ping']);
    }

    /**
     * Ensure the stream has a message_stop if we opened a message and the
     * upstream never delivered a final one (e.g. loop aborted).
     */
    public function ensureClosed(): void
    {
        if (!$this->messageStarted || $this->closed) {
            return;
        }

        $this->emitEvent('message_stop', ['type' => 'message_stop']);
        $this->closed = true;
    }

    public function nextBlockIndex(): int
    {
        return $this->nextBlockIndex;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $suppressToolNames
     */
    private function relayContentBlock(string $event, array $data, array $suppressToolNames): bool
    {
        $type = (string) ($data['type'] ?? $event);
        $upstreamIndex = isset($data['index']) ? (int) $data['index'] : null;

        if ('content_block_start' === $type) {
            $block = $data['content_block'] ?? null;
            if (\is_array($block) && 'tool_use' === ($block['type'] ?? '')) {
                $name = (string) ($block['name'] ?? '');
                if ('' !== $name && \in_array($name, $suppressToolNames, true)) {
                    // Hide our server-side tool_use from the client; remember
                    // the upstream index so deltas/stops for it are dropped.
                    if (null !== $upstreamIndex) {
                        $this->indexMap[$upstreamIndex] = -1;
                    }

                    return false;
                }
            }

            $clientIndex = $this->nextBlockIndex++;
            if (null !== $upstreamIndex) {
                $this->indexMap[$upstreamIndex] = $clientIndex;
            }
            $data['index'] = $clientIndex;
            $this->emitEvent($event, $data);

            return true;
        }

        if (null === $upstreamIndex) {
            return false;
        }

        if (!\array_key_exists($upstreamIndex, $this->indexMap)) {
            return false;
        }

        $clientIndex = $this->indexMap[$upstreamIndex];
        if ($clientIndex < 0) {
            return false; // suppressed tool_use block
        }

        $data['index'] = $clientIndex;
        $this->emitEvent($event, $data);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        ($this->emit)([
            'event' => $event,
            'data' => $data,
        ]);
    }
}
