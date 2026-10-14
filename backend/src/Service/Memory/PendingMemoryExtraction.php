<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\Message\PerfPipelineFlag;
use Psr\Log\LoggerInterface;

/**
 * The memory-extraction job a chat turn queues once its answer exists.
 *
 * Built by the handler, dispatched either at once or — when the caller
 * persists the assistant message itself — after that write (#881), so
 * the worker never runs before the message it annotates exists.
 */
final readonly class PendingMemoryExtraction
{
    public function __construct(
        private MemoryExtractionDispatcher $memoryExtractionDispatcher,
        private PerfPipelineFlag $perfPipelineFlag,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build the (skip-aware) extraction payload without touching the bus.
     *
     * Honours the `PERF.V2_PIPELINE_ENABLED` kill switch and the same
     * disable flags used during context loading, so widgets and
     * operator-disabled users never trigger an extraction run.
     *
     * The reason for skipping is logged precisely. A request-level opt-out
     * (widget embed, guest-mode UI toggle, explicit `disable_memories`)
     * and a user disabling memories in their profile settings produce
     * different log messages, and the request-level case also carries the
     * specific lever (channel, classification source, flag value) in the
     * log context — operators don't have to spelunk through user records
     * to tell the cases apart.
     *
     * Returns either a ready-to-dispatch `ExtractMemoriesCommand` or
     * `null` when extraction must be skipped. Pair with
     * {@see dispatch()} to actually fire it. The
     * split exists so the StreamController can defer the dispatch until
     * after the outgoing assistant message is persisted (issue #881
     * race fix).
     *
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<int, array<string, mixed>>                         $loadedMemories
     * @param bool                                                     $disabledByRequest     true when the caller (widget channel / `disable_memories` option) opted out
     * @param bool                                                     $disabledByUser        true when the user's profile setting has memories disabled
     * @param array<string, mixed>                                     $requestDisableContext optional log context describing which request-level lever fired (channel, classification source, disable_memories flag) — built by {@see loadMemoriesContext()}
     * @param bool                                                     $incognito             true for incognito turns: memories were READ normally, but nothing from the conversation may be extracted/written
     */
    public function build(
        Message $message,
        string $aiResponse,
        array $thread,
        array $loadedMemories,
        bool $disabledByRequest,
        bool $disabledByUser,
        array $requestDisableContext = [],
        bool $incognito = false,
    ): ?ExtractMemoriesCommand {
        if ($incognito) {
            // Incognito is read-only for memories: extraction would persist
            // conversation content to Qdrant, which the mode forbids. (Also,
            // the transient message has no id for the queued command.)
            $this->logger->info('ChatHandler: Incognito turn — memory extraction skipped', [
                'user_id' => $message->getUserId(),
            ]);

            return null;
        }

        if ($disabledByRequest) {
            $this->logger->info('ChatHandler: Memory extraction disabled by request, skipping', array_merge([
                'user_id' => $message->getUserId(),
            ], $requestDisableContext));

            return null;
        }

        if ($disabledByUser) {
            $this->logger->info('ChatHandler: Memory extraction disabled by user setting, skipping', [
                'user_id' => $message->getUserId(),
            ]);

            return null;
        }

        if (!$this->perfPipelineFlag->isEnabled($message->getUserId())) {
            // Phase 4 kill switch: the operator has disabled the v2 perf
            // pipeline for this user (or globally). Skip memory extraction
            // entirely rather than reviving the inline path. Memories will
            // still get picked up the next time the user sends a message
            // with the flag re-enabled.
            $this->logger->info('ChatHandler: PERF.V2_PIPELINE disabled — skipping memory extraction', [
                'user_id' => $message->getUserId(),
            ]);

            return null;
        }

        $threadSnapshot = $this->normalizeThreadForQueue($thread);

        return new ExtractMemoriesCommand(
            messageId: $message->getId(),
            userId: $message->getUserId(),
            aiResponse: $aiResponse,
            threadSnapshot: $threadSnapshot,
            relevantMemories: $loadedMemories,
        );
    }

    /**
     * Forward a prepared extraction command to the messenger bus.
     *
     * Thin proxy around {@see MemoryExtractionDispatcher::dispatch()} so
     * the synchronous (non-deferred) handler paths inside this class can
     * keep their existing call sites. The deferred SSE path lives in
     * {@see \App\Controller\StreamController} and goes through the same
     * dispatcher service directly to avoid duplicating the dispatch +
     * log + swallow contract (Copilot review of PR #939).
     */
    public function dispatch(?ExtractMemoriesCommand $command): void
    {
        $this->memoryExtractionDispatcher->dispatch($command);
    }

    /**
     * Flatten a thread (which may mix Message entities and plain arrays) into
     * a JSON-serialisable shape that survives the messenger queue boundary.
     *
     * Doctrine entities cannot be safely serialised — their lazy proxies
     * carry an EntityManager reference that is invalid in the worker. The
     * extraction flow only needs `role`+`content`, so reduce to that.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeThreadForQueue(array $thread): array
    {
        $out = [];
        foreach ($thread as $entry) {
            if ($entry instanceof Message) {
                $out[] = [
                    'role' => 'IN' === $entry->getDirection() ? 'user' : 'assistant',
                    'content' => $entry->getText(),
                ];
                continue;
            }

            if (is_array($entry)) {
                $role = (string) ($entry['role'] ?? 'user');
                $content = $entry['content'] ?? '';
                if (!is_string($content)) {
                    // Some callers (vision messages) embed multi-part arrays
                    // as content. Encode them so the worker can still see the
                    // text portion without re-implementing the multimodal
                    // parser. Fall back to JSON if encoding succeeds.
                    $encoded = json_encode($content);
                    $content = false === $encoded ? '' : $encoded;
                }
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }
}
