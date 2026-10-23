<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Synthesizes one client-visible Anthropic SSE message across N upstream turns
 * (MCP tool loop). Remaps content_block indices into a monotonic space and
 * suppresses intermediate message_start / message_delta / message_stop so the
 * client sees a single logical message.
 *
 * Client tool_use blocks are held until message_delta. A mixed turn (Synaplan
 * still has to read a page) drops them; a client-only turn flushes them first.
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
    private bool $emittedText = false;
    private bool $holdingClientTools = false;
    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $held = [];
    /** @var array<int, true> upstream indices buffered until message_delta */
    private array $heldBlockIndices = [];
    /** @var array<int, true> upstream indices of client tool_use blocks */
    private array $heldClientToolIndices = [];
    /** @var array<string, true> server tools hidden from the client, across every turn */
    private array $hiddenToolNames = [];
    /** True once those names have been written onto a client-visible message_delta. */
    private bool $hiddenToolsAnnounced = false;

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
        $this->held = [];
        $this->heldBlockIndices = [];
        $this->heldClientToolIndices = [];
        $this->holdingClientTools = false;
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
            return $this->holdOrRelayContentBlock($event, $data, $type, $suppressToolNames);
        }

        if ('message_delta' === $type || 'message_stop' === $type) {
            $this->releaseHeld($isFinalTurn, $suppressToolNames);
            if (!$isFinalTurn) {
                return false;
            }
            if ('message_delta' === $type) {
                $data = $this->withHiddenTools($data);
            }
            $this->emitEvent($event, $data);
            if ('message_stop' === $type) {
                $this->closed = true;
            }

            return true;
        }

        if ('error' === $type) {
            $this->releaseHeld(true, $suppressToolNames);
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
     *
     * Loop exhaustion, the wall-clock break, and wrap-up recovery close here
     * without a relayed final message_delta. Name the hidden server tools on
     * a delta first, or the client never learns that Synaplan searched or read.
     */
    public function ensureClosed(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->messageStarted) {
            $this->releaseHeld(true, []);
        }

        if (!$this->messageStarted || $this->closed) {
            return;
        }

        if (!$this->hiddenToolsAnnounced && [] !== $this->hiddenToolNames) {
            $this->emitEvent('message_delta', $this->withHiddenTools([
                'type' => 'message_delta',
                'delta' => [],
            ]));
        }

        $this->emitEvent('message_stop', ['type' => 'message_stop']);
        $this->closed = true;
    }

    public function nextBlockIndex(): int
    {
        return $this->nextBlockIndex;
    }

    /**
     * Buffer a client tool_use (and anything after it) until message_delta.
     * Server tools in the suppress list stay on the live path, which drops them.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $suppressToolNames
     */
    private function holdOrRelayContentBlock(string $event, array $data, string $type, array $suppressToolNames): bool
    {
        $idx = isset($data['index']) ? (int) $data['index'] : null;
        $toolName = $this->toolUseName($type, $data);

        if (null !== $toolName && '' !== $toolName && \in_array($toolName, $suppressToolNames, true)) {
            return $this->relayContentBlock($event, $data, $suppressToolNames);
        }

        if (null !== $toolName) {
            $this->holdingClientTools = true;
            if (null !== $idx) {
                $this->heldBlockIndices[$idx] = true;
                $this->heldClientToolIndices[$idx] = true;
            }
            $this->held[] = ['event' => $event, 'data' => $data];

            return false;
        }

        if (null !== $idx && isset($this->heldBlockIndices[$idx])) {
            $this->held[] = ['event' => $event, 'data' => $data];

            return false;
        }

        if ($this->holdingClientTools && 'content_block_start' === $type) {
            if (null !== $idx) {
                $this->heldBlockIndices[$idx] = true;
            }
            $this->held[] = ['event' => $event, 'data' => $data];

            return false;
        }

        return $this->relayContentBlock($event, $data, $suppressToolNames);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toolUseName(string $type, array $data): ?string
    {
        if ('content_block_start' !== $type) {
            return null;
        }
        $block = $data['content_block'] ?? null;
        if (!\is_array($block) || 'tool_use' !== ($block['type'] ?? '')) {
            return null;
        }

        return (string) ($block['name'] ?? '');
    }

    /**
     * @param list<string> $suppressToolNames
     */
    private function releaseHeld(bool $keepClientTools, array $suppressToolNames): void
    {
        $held = $this->held;
        $clientTools = $this->heldClientToolIndices;
        $this->held = [];
        $this->heldBlockIndices = [];
        $this->heldClientToolIndices = [];
        $this->holdingClientTools = false;

        foreach ($held as $item) {
            $idx = isset($item['data']['index']) ? (int) $item['data']['index'] : null;
            if (!$keepClientTools && null !== $idx && isset($clientTools[$idx])) {
                continue;
            }
            $this->relayContentBlock($item['event'], $item['data'], $suppressToolNames);
        }
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
                    $this->hiddenToolNames[$name] = true;

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
        $delta = $data['delta'] ?? [];
        if (\is_array($delta) && 'text_delta' === ($delta['type'] ?? '')) {
            $text = (string) ($delta['text'] ?? '');
            if ('' !== $text) {
                $this->emittedText = true;
            }
        }
        $this->emitEvent($event, $data);

        return true;
    }

    public function hasEmittedText(): bool
    {
        return $this->emittedText;
    }

    /**
     * Synthesize a client-visible text block when the upstream finished a
     * tool loop with nothing the person can read.
     */
    public function emitAssistantText(string $text): void
    {
        if ($this->closed || '' === $text) {
            return;
        }
        if (!$this->messageStarted) {
            $this->messageStarted = true;
            $this->emitEvent('message_start', [
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_synaplan_empty',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [],
                ],
            ]);
        }
        $idx = $this->nextBlockIndex++;
        $this->emitEvent('content_block_start', [
            'type' => 'content_block_start',
            'index' => $idx,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);
        $this->emitEvent('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => $idx,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
        $this->emitEvent('content_block_stop', [
            'type' => 'content_block_stop',
            'index' => $idx,
        ]);
        $this->emittedText = true;
    }

    /**
     * The client never saw these blocks; name them on a message_delta so it
     * can still tell that Synaplan searched or read on its behalf.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withHiddenTools(array $data): array
    {
        if ($this->hiddenToolsAnnounced || [] === $this->hiddenToolNames) {
            return $data;
        }

        $delta = \is_array($data['delta'] ?? null) ? $data['delta'] : [];
        $delta['synaplan_server_tools'] = array_keys($this->hiddenToolNames);
        $data['delta'] = $delta;
        $this->hiddenToolsAnnounced = true;

        return $data;
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
