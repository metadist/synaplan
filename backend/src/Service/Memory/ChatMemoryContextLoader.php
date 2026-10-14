<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Feedback\FeedbackConfigService;
use App\Service\Feedback\FeedbackConstants;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\Message\ConversationHistoryRenderer;
use App\Service\Message\ProgressCallback;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * What a chat turn knows about the person: relevant memories, feedback
 * corrections, and digests of key messages from older conversations.
 *
 * The query is embedded at most once per vector space and shared by
 * every search of the turn (knowledge base included).
 */
final readonly class ChatMemoryContextLoader
{
    public function __construct(
        private UserMemoryService $memoryService,
        private ModelConfigService $modelConfigService,
        private FeedbackConfigService $feedbackConfig,
        private KnowledgeContextFormatter $knowledgeContextFormatter,
        private DigestSearchService $digestSearchService,
        private MessageDigestConfig $digestConfig,
        private ConversationHistoryRenderer $history,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build the lazy embedding cache used across RAG, memory, and feedback
     * lookups for a single chat request.
     *
     * The closure returns the user-query embedding once and reuses it on
     * subsequent calls — paying the embed HTTP cost (50-250 ms) only once
     * per request even when three independent vector searches happen.
     * Returns null for empty-text messages or when the memory service is
     * unavailable (callers then fall back to text-based search).
     *
     * Shared by `ChatHandler::handleStream()` and the email/generic webhook
     * path (`ChatHandler::handle()`), so both get the same caching behaviour
     * instead of paying the embed cost twice. See issue #615.
     *
     * @return \Closure(): ?array<int, float>
     */
    public function queryVectorResolver(Message $message, PerfTimer $perfTimer): \Closure
    {
        $sharedQueryVector = null;
        $sharedVectorComputed = false;

        return function () use ($message, &$sharedQueryVector, &$sharedVectorComputed, $perfTimer): ?array {
            if ($sharedVectorComputed) {
                return $sharedQueryVector;
            }
            $sharedVectorComputed = true;

            $text = $message->getText();
            if ('' === trim($text) || !$this->memoryService->isAvailable()) {
                return null;
            }

            $perfTimer->start('shared_embedding');
            try {
                $embed = $this->memoryService->embedUserQuery($message->getUserId(), $text);
            } catch (\Throwable $e) {
                $this->logger->warning('ChatHandler: shared embed failed, falling back to per-call embeds', [
                    'error' => $e->getMessage(),
                ]);
                $embed = null;
            }
            $perfTimer->stop('shared_embedding');

            if (null !== $embed && !empty($embed['embedding'])) {
                $sharedQueryVector = $embed['embedding'];
            }

            return $sharedQueryVector;
        };
    }

    /**
     * Lazily produce the embedding used for Qdrant *memory* searches.
     *
     * The memories collection is pinned to its own embedding model
     * (see {@see UserMemoryService::getMemoryEmbeddingModelId()}) which
     * may diverge from the active VECTORIZE default — that's the
     * whole point of PR #985 (no data loss on switch). Memory and
     * feedback searches therefore need a separate vector cache: using
     * the shared VECTORIZE vector would be rejected by Qdrant (wrong
     * dimension) or returned as zero hits (stale-filter mismatch).
     *
     * Optimisation: when the memory-pinned model happens to equal the
     * active VECTORIZE model (the typical case for fresh installs and
     * for ops who haven't switched yet), this resolver reuses the
     * already-computed shared vector instead of paying for a second
     * embed call.
     *
     * @return \Closure(): ?array<int, float>
     */
    public function memoryVectorResolver(
        Message $message,
        \Closure $resolveSharedVector,
        PerfTimer $perfTimer,
    ): \Closure {
        $memoryQueryVector = null;
        $memoryVectorComputed = false;

        return function () use (
            $message,
            $resolveSharedVector,
            $perfTimer,
            &$memoryQueryVector,
            &$memoryVectorComputed,
        ): ?array {
            if ($memoryVectorComputed) {
                return $memoryQueryVector;
            }
            $memoryVectorComputed = true;

            $text = $message->getText();
            if ('' === trim($text) || !$this->memoryService->isAvailable()) {
                return null;
            }

            // Reuse the VECTORIZE-embedded vector when the memory model
            // matches VECTORIZE. Keeps the existing 1-embed-per-message
            // performance for the 99% of installations that never swap.
            $stickyMemoryModelId = $this->memoryService->getMemoryEmbeddingModelId();
            $vectorizeModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $message->getUserId());
            if (null !== $stickyMemoryModelId && $stickyMemoryModelId === $vectorizeModelId) {
                $shared = $resolveSharedVector();
                if (null !== $shared) {
                    return $memoryQueryVector = $shared;
                }
            }

            // Memory model diverges from VECTORIZE (or shared vector
            // is unavailable) — embed the query against the memory
            // model directly so reads land in the right vector space.
            $perfTimer->start('memory_embedding');
            try {
                $embed = $this->memoryService->embedQueryForMemorySearch($message->getUserId(), $text);
            } catch (\Throwable $e) {
                $this->logger->warning('ChatHandler: memory embed failed, falling back to per-call embeds', [
                    'error' => $e->getMessage(),
                ]);
                $embed = null;
            }
            $perfTimer->stop('memory_embedding');

            if (null !== $embed && !empty($embed['embedding'])) {
                $memoryQueryVector = $embed['embedding'];
            }

            return $memoryQueryVector;
        };
    }

    /**
     * Load relevant user memories from Qdrant and build the system-prompt
     * fragment that injects them into the AI context.
     *
     * Shared by `ChatHandler::handle()` and `handleStream()` so every channel
     * (Web UI, email, generic API webhook) treats memories the same way.
     * Returns empty strings/arrays when memories are disabled by request,
     * disabled per-user, or the memory service is unavailable.
     *
     * SSE-style memory-loaded events are forwarded to `$progressCallback`
     * when one is supplied so the Web UI can render the memory badge.
     * Email/webhook callers pass `null` and simply discard the event.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     *
     * @return array{
     *     context: string,
     *     memories: array<int, array<string, mixed>>,
     *     disabledByRequest: bool,
     *     disabledByUser: bool,
     *     requestDisableContext: array{
     *         channel: string|null,
     *         classification_source: string|null,
     *         disable_memories_flag: bool
     *     }
     * }
     *                                  `disabledByRequest` is true when the *caller* (widget channel /
     *                                  `disable_memories` option) opted out; `disabledByUser` is true when
     *                                  the user has disabled memories in their settings. They are returned
     *                                  separately so {@see PendingMemoryExtraction::build()} can log the
     *                                  specific reason (issue PR #925 Copilot review).
     *                                  `requestDisableContext` carries which lever fired (widget channel,
     *                                  classification source, or explicit `disable_memories` flag) so the
     *                                  precise cause is visible in production logs.
     */
    public function loadMemories(
        Message $message,
        ?User $user,
        array $options,
        array $classification,
        ?callable $progressCallback,
        \Closure $resolveMemoryVector,
        PerfTimer $perfTimer,
    ): array {
        $disabledByRequest = self::disabledByRequest($options, $classification);

        $disabledByUser = !($user?->isMemoriesEnabled() ?? true);

        // Captures *why* this request opted out (widget channel vs explicit
        // disable_memories flag vs widget classification source). Surfaced
        // in both the memories-load and the dispatch log so operators can
        // tell apart widget embeds from e.g. a guest-mode UI toggle that
        // also sets `disable_memories` — both share the same code path but
        // need different debugging.
        $requestDisableContext = [
            'channel' => $options['channel'] ?? null,
            'classification_source' => $classification['source'] ?? null,
            'disable_memories_flag' => !empty($options['disable_memories']),
        ];

        $loadedMemories = [];

        if ($disabledByRequest) {
            $this->logger->debug('ChatHandler: Memories disabled by request, skipping memories', array_merge([
                'user_id' => $message->getUserId(),
            ], $requestDisableContext));
        } elseif ($disabledByUser) {
            $this->logger->debug('ChatHandler: Memories disabled by user setting, skipping memories', [
                'user_id' => $message->getUserId(),
            ]);
        } elseif ($this->memoryService->isAvailable()) {
            $loadedMemories = $this->searchMemories($message, $resolveMemoryVector, $perfTimer, $progressCallback);
        } else {
            $this->logger->debug('ChatHandler: Memory service not available, skipping memories');
        }

        $memoriesContext = '';
        if (!empty($loadedMemories)) {
            $memoriesContext = $this->knowledgeContextFormatter->formatMemoriesContext($loadedMemories);

            $this->logger->info('ChatHandler: User memories loaded', [
                'user_id' => $message->getUserId(),
                'memories_count' => count($loadedMemories),
            ]);

            if ($progressCallback) {
                $progressCallback([
                    'status' => 'memories_loaded',
                    'message' => 'Memories loaded',
                    'metadata' => [
                        'memories' => $loadedMemories,
                        'count' => count($loadedMemories),
                    ],
                    'timestamp' => time(),
                ]);
            }
        } else {
            $this->logger->debug('ChatHandler: No relevant memories found', [
                'user_id' => $message->getUserId(),
            ]);
        }

        return [
            'context' => $memoriesContext,
            'memories' => $loadedMemories,
            'disabledByRequest' => $disabledByRequest,
            'disabledByUser' => $disabledByUser,
            'requestDisableContext' => $requestDisableContext,
        ];
    }

    /**
     * Load feedback (false positives + positives) from Qdrant and build the
     * system-prompt fragment that injects them into the AI context.
     *
     * Same shape as {@see loadMemoriesContext}: shared between streaming
     * and non-streaming chat paths so the email channel and the Web UI
     * see identical feedback context.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     *
     * @return array{context: string, feedbacks: array<int, array<string, mixed>>}
     */
    public function loadFeedback(
        Message $message,
        ?User $user,
        array $options,
        array $classification,
        ?callable $progressCallback,
        \Closure $resolveMemoryVector,
        PerfTimer $perfTimer,
    ): array {
        $disabledByRequest = self::disabledByRequest($options, $classification);

        $disabledByUser = !($user?->isMemoriesEnabled() ?? true);

        $feedbackContext = '';
        $loadedFeedbacks = [];

        if ($disabledByRequest || $disabledByUser || !$this->memoryService->isAvailable()) {
            return [
                'context' => $feedbackContext,
                'feedbacks' => $loadedFeedbacks,
            ];
        }

        try {
            $perfTimer->start('feedback');
            $memoryVector = $resolveMemoryVector();
            $falsePositives = $this->searchFeedback(
                $message->getUserId(),
                $message->getText(),
                'feedback_negative',
                FeedbackConstants::NAMESPACE_FALSE_POSITIVE,
                $memoryVector
            );

            $positiveExamples = $this->searchFeedback(
                $message->getUserId(),
                $message->getText(),
                'feedback_positive',
                FeedbackConstants::NAMESPACE_POSITIVE,
                $memoryVector
            );
            $perfTimer->stop('feedback');

            if (!empty($falsePositives) || !empty($positiveExamples)) {
                [$feedbackContext, $loadedFeedbacks] = self::feedbackBlock($falsePositives, $positiveExamples);

                if ($progressCallback) {
                    $progressCallback([
                        'status' => 'feedback_loaded',
                        'message' => 'Feedback examples loaded',
                        'metadata' => [
                            'feedbacks' => $loadedFeedbacks,
                            'count' => count($loadedFeedbacks),
                        ],
                        'timestamp' => time(),
                    ]);
                }

                $this->logger->info('ChatHandler: Feedback examples loaded', [
                    'user_id' => $message->getUserId(),
                    'false_positives_count' => count($falsePositives),
                    'positive_examples_count' => count($positiveExamples),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ChatHandler: Failed to load feedback examples', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'context' => $feedbackContext,
            'feedbacks' => $loadedFeedbacks,
        ];
    }

    /**
     * Deep-memory retrieval over the message digest index: find key messages
     * from older turns — including earlier in this chat, outside the verbatim
     * history window — that are relevant to the current prompt and inject them
     * (plus verbatim excerpts for the top hits) into the system prompt.
     *
     * Shared between streaming and non-streaming paths (channel parity), and
     * gated by exactly the same request/user levers as memories: a widget or
     * guest visitor must never see the account owner's message history.
     *
     * Reuses the per-turn memory embedding (digest titles are embedded with
     * the same memory embedding model), so this adds no extra embed call.
     *
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $options
     * @param array<string, mixed>                                     $classification
     *
     * @return array{context: string, digests: list<array<string, mixed>>}
     */
    public function loadDigests(
        Message $message,
        array $thread,
        ?User $user,
        array $options,
        array $classification,
        ?callable $progressCallback,
        \Closure $resolveMemoryVector,
        PerfTimer $perfTimer,
        bool $promptIncludesFileText,
    ): array {
        $disabledByRequest = self::disabledByRequest($options, $classification);
        $disabledByUser = !($user?->isMemoriesEnabled() ?? true);

        if ($disabledByRequest || $disabledByUser || !$this->digestConfig->isEnabled()) {
            return ['context' => '', 'digests' => []];
        }

        $digests = [];

        try {
            $perfTimer->start('digests_search');
            $memoryVector = $resolveMemoryVector();
            if (null !== $memoryVector) {
                $digests = $this->digestSearchService->search(
                    $message->getUserId(),
                    $memoryVector,
                    excludeMessageIds: $this->history->verbatimMessageIds($thread, $message, $promptIncludesFileText),
                );
            }
            $perfTimer->stop('digests_search');
        } catch (\Throwable $e) {
            $this->logger->warning('ChatHandler: Failed to load message digests, continuing without', [
                'error' => $e->getMessage(),
            ]);
            $digests = [];
        }

        $tail = [];
        try {
            $tail = $this->digestSearchService->recentOtherChatTail(
                $message->getUserId(),
                $message->getChatId(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ChatHandler: Failed to load other-chat tail, continuing without', [
                'error' => $e->getMessage(),
            ]);
        }

        if ([] !== $tail) {
            $tail = self::tailWithoutDigests($tail, $digests);
        }

        if ([] === $digests && [] === $tail) {
            return ['context' => '', 'digests' => []];
        }

        $budget = $this->digestConfig->getBlockMaxChars();
        $context = $this->knowledgeContextFormatter->formatDigestContext($digests, $budget);
        $remaining = max(0, $budget - mb_strlen($context));
        $context .= $this->knowledgeContextFormatter->formatOtherChatTail($tail, $remaining);
        $digests = array_merge($digests, $tail);

        $this->logger->info('ChatHandler: Message digests loaded', [
            'user_id' => $message->getUserId(),
            'digest_count' => count($digests),
            'context_length' => strlen($context),
        ]);

        if ($progressCallback && '' !== $context) {
            // Excerpts stay backend-only: the frontend needs the reference
            // list (id, chat, title) for [Message:ID] badges, not the pulled
            // message bodies.
            $progressCallback([
                'status' => 'digests_loaded',
                'message' => 'Older conversations loaded',
                'metadata' => [
                    'digests' => array_map(static fn (array $d): array => [
                        'message_id' => $d['message_id'],
                        'chat_id' => $d['chat_id'],
                        'title' => $d['title'],
                        'channel' => $d['channel'],
                        'source_date' => $d['source_date'],
                    ], $digests),
                    'count' => count($digests),
                ],
                'timestamp' => time(),
            ]);
        }

        return ['context' => $context, 'digests' => $digests];
    }

    /**
     * Search feedback entries from Qdrant with score filtering.
     *
     * Phase 1a: callers may supply a precomputed `$sharedVector` to skip the
     * embedding round-trip. ChatHandler calls this twice (negative + positive
     * namespaces) off the same user query, so paying the embed once and
     * passing the vector through saves a full embed call per request.
     *
     * @param array<int, float>|null $sharedVector Precomputed embedding (skips internal embed)
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchFeedback(int $userId, string $queryText, string $category, string $namespace, ?array $sharedVector = null): array
    {
        $minScore = $this->feedbackConfig->getMinChatFeedbackScore();

        if (null !== $sharedVector && !empty($sharedVector)) {
            $results = $this->memoryService->searchMemoriesByVector(
                $userId,
                $sharedVector,
                category: $category,
                limit: $this->feedbackConfig->getLimitPerNamespace(),
                minScore: $minScore,
                namespace: $namespace,
                includeHidden: true
            );
        } else {
            $results = $this->memoryService->searchRelevantMemories(
                $userId,
                $queryText,
                category: $category,
                limit: $this->feedbackConfig->getLimitPerNamespace(),
                minScore: $minScore,
                namespace: $namespace,
                includeHidden: true
            );
        }

        return $this->filterByScore($results, $minScore);
    }

    /**
     * Post-filter results by score (safety net — Qdrant may return below-threshold results).
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByScore(array $items, float $minScore): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item) => ($item['score'] ?? 0) >= $minScore
        ));
    }

    /**
     * Qdrant search for the memories relevant to this message. A failing
     * store is not a failing turn: the answer goes out without memories.
     *
     * @param \Closure(): ?array<int, float> $resolveMemoryVector
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchMemories(Message $message, \Closure $resolveMemoryVector, PerfTimer $perfTimer, ?callable $progressCallback): array
    {
        $loadedMemories = [];

        try {
            $this->logger->debug('ChatHandler: Loading user memories', [
                'user_id' => $message->getUserId(),
                'message_text' => substr($message->getText(), 0, 100),
            ]);

            ProgressCallback::notify($progressCallback, 'checking_memories', 'Checking memories...');

            $perfTimer->start('memories_search');
            $memoryVector = $resolveMemoryVector();
            if (null !== $memoryVector) {
                $rawMemories = $this->memoryService->searchMemoriesByVector(
                    $message->getUserId(),
                    $memoryVector,
                    limit: $this->feedbackConfig->getMaxChatMemories(),
                    minScore: $this->feedbackConfig->getMinChatMemoryScore()
                );
            } else {
                $rawMemories = $this->memoryService->searchRelevantMemories(
                    $message->getUserId(),
                    $message->getText(),
                    limit: $this->feedbackConfig->getMaxChatMemories(),
                    minScore: $this->feedbackConfig->getMinChatMemoryScore()
                );
            }
            $perfTimer->stop('memories_search');

            $loadedMemories = $this->filterByScore($rawMemories, $this->feedbackConfig->getMinChatMemoryScore());

            $this->logger->debug('ChatHandler: Memories loaded from Qdrant', [
                'count' => count($loadedMemories),
                'filtered_out' => count($rawMemories) - count($loadedMemories),
                'memories' => array_map(fn ($m) => [
                    'id' => $m['id'] ?? null,
                    'key' => $m['key'] ?? null,
                    'score' => $m['score'] ?? 0,
                ], $loadedMemories),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ChatHandler: Failed to load memories, continuing without', [
                'error' => $e->getMessage(),
            ]);
            $loadedMemories = [];
        }

        return $loadedMemories;
    }

    /**
     * Widget conversations and an explicit `disable_memories` never read or
     * write memories.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    private static function disabledByRequest(array $options, array $classification): bool
    {
        return !empty($options['disable_memories'])
            || ('WIDGET' === ($options['channel'] ?? null))
            || ('widget' === ($classification['source'] ?? null));
    }

    /**
     * The feedback section of the system prompt and the entries it cites.
     *
     * @param array<int, array<string, mixed>> $falsePositives
     * @param array<int, array<string, mixed>> $positiveExamples
     *
     * @return array{0: string, 1: list<array{id: mixed, type: string, value: mixed}>}
     */
    private static function feedbackBlock(array $falsePositives, array $positiveExamples): array
    {
        $loadedFeedbacks = [];
        $feedbackContext = "\n\n## User Feedback (corrections from previous conversations):\n";

        if (!empty($falsePositives)) {
            $feedbackContext .= "\n### Things to AVOID (user-reported false positives) - Reference as [Feedback:ID]:\n";
            foreach ($falsePositives as $fp) {
                $feedbackContext .= sprintf("- ❌ [Feedback:%d] %s\n", $fp['id'], $fp['value']);
                $loadedFeedbacks[] = [
                    'id' => $fp['id'],
                    'type' => 'false_positive',
                    'value' => $fp['value'],
                ];
            }
        }

        if (!empty($positiveExamples)) {
            $feedbackContext .= "\n### Correct information (user-confirmed) - Reference as [Feedback:ID]:\n";
            foreach ($positiveExamples as $pe) {
                $feedbackContext .= sprintf("- ✅ [Feedback:%d] %s\n", $pe['id'], $pe['value']);
                $loadedFeedbacks[] = [
                    'id' => $pe['id'],
                    'type' => 'positive',
                    'value' => $pe['value'],
                ];
            }
        }

        $feedbackContext .= "\nREFERENCES: Use [Feedback:ID] (clickable). Rules:\n";
        $feedbackContext .= "- ONE ID per bracket. Good: [Feedback:123] and [Feedback:456]. Bad: [Feedback:123, 456].\n";
        $feedbackContext .= "- Avoid repeating ❌ claims. Prefer ✅ information.\n";
        $feedbackContext .= "- If ❌ and ✅ entries contradict each other, mention the conflict to the user.\n";

        return [$feedbackContext, $loadedFeedbacks];
    }

    /**
     * Other-chat tail rows a digest does not already cite, each once.
     *
     * @param array<int, array<string, mixed>> $tail
     * @param array<int, array<string, mixed>> $digests
     *
     * @return list<array<string, mixed>>
     */
    private static function tailWithoutDigests(array $tail, array $digests): array
    {
        $seen = [];
        foreach ($digests as $digest) {
            $seen[(int) $digest['message_id']] = true;
        }
        $dedupedTail = [];
        foreach ($tail as $row) {
            if (!isset($seen[(int) $row['message_id']])) {
                $dedupedTail[] = $row;
                $seen[(int) $row['message_id']] = true;
            }
        }

        return $dedupedTail;
    }
}
