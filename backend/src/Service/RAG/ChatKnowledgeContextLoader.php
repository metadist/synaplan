<?php

declare(strict_types=1);

namespace App\Service\RAG;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\Message\ProgressCallback;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * The knowledge-base context of a chat turn: which folders to search,
 * with what limit and threshold, whether an assistant searches at all,
 * and the formatted passages plus their citation sources.
 */
final readonly class ChatKnowledgeContextLoader
{
    public function __construct(
        private VectorSearchService $vectorSearchService,
        private KnowledgeContextFormatter $knowledgeContextFormatter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * RAG scope for this turn: an explicit caller scope (widget / API
     * `rag_group_key`) wins, then the RuntimeProfile, then the defaults.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: string|null, 1: int, 2: float} group key, limit, min score
     */
    public function settings(?RuntimeProfile $profile, array $classification, array $options): array
    {
        $groupKey = $options['rag_group_key'] ?? $classification['rag_group_key'] ?? null;
        $limit = $options['rag_limit'] ?? $classification['rag_limit'] ?? null;
        $minScore = $options['rag_min_score'] ?? $classification['rag_min_score'] ?? null;

        if ($profile instanceof RuntimeProfile) {
            $groupKey ??= $profile->primaryRagGroupKey();
            $limit ??= $profile->ragLimit;
            $minScore ??= $profile->ragMinScore;
        }

        return [
            is_string($groupKey) && '' !== $groupKey ? $groupKey : null,
            null !== $limit ? max(1, min(50, (int) $limit)) : 20,
            null !== $minScore ? max(0.0, min(1.0, (float) $minScore)) : 0.2,
        ];
    }

    /**
     * Explicit RAG scopes for an assistant chat: exactly the folders the runtime
     * resolved (already intersected with the owner's live grants in
     * {@see \App\Service\Agent\AgentRuntimeResolver}), plus the talking user's
     * own files only when the assistant opts in via `knowledge.includeUserFiles`.
     *
     * Returns null for a non-assistant turn, which keeps the viewer's normal
     * IAM scope expansion. Returning an explicit (possibly empty) list makes an
     * assistant search exactly its knowledge and nothing of the reader's — C6.
     *
     * @return list<RagScope>|null
     */
    public function assistantScopes(?RuntimeProfile $profile, int $viewerId): ?array
    {
        if (!$profile instanceof RuntimeProfile || null === $profile->agentId) {
            return null;
        }

        $scopes = [];
        foreach ($profile->ragScopes as $scope) {
            $groupKey = $scope['groupKey'];
            $scopes[] = new RagScope(
                $scope['ownerId'],
                '' === $groupKey ? null : $groupKey,
                $scope['fileIds'] ?? [],
            );
        }
        if ($profile->includeUserFiles) {
            $scopes[] = new RagScope($viewerId, null);
        }

        return $scopes;
    }

    /**
     * A profile's own folder is not an explicit search request. Passing it as
     * "already searching" would skip the greeting check on every assistant turn.
     *
     * @param list<RagScope>|null  $agentScopes
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    public function shouldSearch(
        ?array $agentScopes,
        string $text,
        array $classification,
        array $options,
        bool $isRagQuery,
    ): bool {
        $explicit = $options['rag_group_key'] ?? $classification['rag_group_key'] ?? null;

        return \App\Service\Agent\AgentKnowledgeSearchGate::shouldSearch(
            $agentScopes,
            $text,
            (is_string($explicit) && '' !== $explicit) || $isRagQuery,
        );
    }

    /**
     * Build messages for non-streaming (JSON format)
     * Like old system: topicPrompt with $stream = false.
     */
    public function load(
        Message $message,
        string $topic,
        ?string $groupKey = null,
        int $limit = 5,
        float $minScore = 0.3,
        ?array $explicitScopes = null,
        bool $forceUserKnowledge = false,
    ): array {
        if (empty($message->getText())) {
            $this->logger->debug('ChatHandler: Skipping RAG context (empty text)', [
                'topic' => $topic,
                'has_text' => false,
            ]);

            return ['context' => '', 'sources' => []];
        }

        if (!$groupKey) {
            if (!$forceUserKnowledge && 'general' === $topic) {
                $this->logger->debug('ChatHandler: Skipping RAG context (general topic, no group key)', [
                    'topic' => $topic,
                ]);

                return ['context' => '', 'sources' => []];
            }

            if (!$forceUserKnowledge) {
                $groupKey = "TASKPROMPT:{$topic}";
            }
        }

        try {
            error_log('🔍 ChatHandler: Attempting to load RAG context for topic: '.$topic.' (groupKey: '.($groupKey ?? 'user-kb').')');
            error_log('🔍 ChatHandler: Searching RAG with groupKey: '.($groupKey ?? 'user-kb').', query: '.substr($message->getText(), 0, 100));

            $ragResults = $this->vectorSearchService->semanticSearch(
                $message->getText(),
                $message->getUserId(),
                $groupKey,
                limit: $limit,
                minScore: $minScore,
                explicitScopes: $explicitScopes,
            );

            error_log('🔍 ChatHandler: RAG search returned '.count($ragResults).' results');

            if (empty($ragResults) && !$forceUserKnowledge && 'general' !== $topic) {
                $fallbackGroupKey = "TASKPROMPT:{$topic}";
                if ($fallbackGroupKey !== $groupKey) {
                    error_log('🔄 ChatHandler: RAG fallback search with groupKey: '.$fallbackGroupKey);
                    $ragResults = $this->vectorSearchService->semanticSearch(
                        $message->getText(),
                        $message->getUserId(),
                        $fallbackGroupKey,
                        limit: $limit,
                        minScore: $minScore,
                        explicitScopes: $explicitScopes,
                    );
                    error_log('🔍 ChatHandler: RAG fallback returned '.count($ragResults).' results');

                    if (!empty($ragResults)) {
                        $groupKey = $fallbackGroupKey;
                    }
                }
            }

            if (empty($ragResults)) {
                return ['context' => '', 'sources' => []];
            }

            $ragContext = $this->knowledgeContextFormatter->formatRagContext($ragResults);

            $this->logger->info('ChatHandler: RAG context loaded', [
                'topic' => $topic,
                'chunks_found' => count($ragResults),
                'user_id' => $message->getUserId(),
                'group_key' => $groupKey,
            ]);

            return [
                'context' => $ragContext,
                'sources' => $this->knowledgeContextFormatter->citationRefs($ragResults),
            ];
        } catch (\Throwable $e) {
            error_log('❌ ChatHandler: RAG context loading failed: '.$e->getMessage());
            error_log('❌ Stack trace: '.$e->getTraceAsString());

            $this->logger->warning('ChatHandler: RAG context loading failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'group_key' => $groupKey,
            ]);

            return ['context' => '', 'sources' => []];
        }
    }

    /**
     * The streamed web chat's knowledge search. Unlike {@see load()} it
     * reuses the turn's shared query embedding, falls back from a topic folder
     * to the task-prompt folder, and reports the search as a progress step.
     *
     * @param list<RagScope>|null            $agentScopes
     * @param \Closure(): ?array<int, float> $resolveSharedVector
     *
     * @return array{context: string, sources: list<array<string, mixed>>}
     */
    public function loadForStream(
        Message $message,
        string $topic,
        ?string $ragGroupKey,
        int $ragLimit,
        float $ragMinScore,
        ?array $agentScopes,
        bool $searchKnowledge,
        bool $isRagQuery,
        \Closure $resolveSharedVector,
        PerfTimer $perfTimer,
        ?callable $progressCallback,
    ): array {
        $ragContext = '';
        $ragSources = [];
        $ragResultsCount = 0;

        if (!$ragGroupKey && 'general' !== $topic && $searchKnowledge) {
            $ragGroupKey = "TASKPROMPT:{$topic}";
        }

        $ragResults = [];

        if (!empty($message->getText()) && $searchKnowledge) {
            try {
                error_log('🔍 ChatHandler: Attempting to load RAG context for topic: '.$topic.' (groupKey: '.$ragGroupKey.')');

                ProgressCallback::notify($progressCallback, 'searching_files', 'Looking for related files...');

                $perfTimer->start('rag');
                $ragResults = $this->searchByTurnVector($message, $ragGroupKey, $ragLimit, $ragMinScore, $agentScopes, $resolveSharedVector);
                $perfTimer->stop('rag');

                error_log('🔍 ChatHandler: RAG search returned '.count($ragResults).' results');

                if (empty($ragResults) && !$isRagQuery && 'general' !== $topic) {
                    $fallbackGroupKey = "TASKPROMPT:{$topic}";
                    if ($fallbackGroupKey !== $ragGroupKey) {
                        error_log('🔄 ChatHandler: RAG fallback search with groupKey: '.$fallbackGroupKey);
                        $perfTimer->start('rag');
                        $ragResults = $this->searchByTurnVector($message, $fallbackGroupKey, $ragLimit, $ragMinScore, $agentScopes, $resolveSharedVector);
                        $perfTimer->stop('rag');
                        error_log('🔍 ChatHandler: RAG fallback returned '.count($ragResults).' results');

                        if (!empty($ragResults)) {
                            $ragGroupKey = $fallbackGroupKey;
                        }
                    }
                }

                if (!empty($ragResults)) {
                    $ragContext = $this->knowledgeContextFormatter->formatRagContext($ragResults);
                    $ragSources = $this->knowledgeContextFormatter->citationRefs($ragResults);
                    $ragResultsCount = count($ragResults);

                    error_log('🔍 ChatHandler: RAG context loaded, total length: '.strlen($ragContext));

                    $this->logger->info('ChatHandler: RAG context loaded', [
                        'topic' => $topic,
                        'chunks_found' => $ragResultsCount,
                        'user_id' => $message->getUserId(),
                        'group_key' => $ragGroupKey,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('❌ ChatHandler: RAG context loading failed: '.$e->getMessage());
                error_log('❌ Stack trace: '.$e->getTraceAsString());

                $this->logger->warning('ChatHandler: RAG context loading failed', [
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                    'group_key' => $ragGroupKey,
                ]);
                // Continue without RAG context
            }
        } else {
            error_log(sprintf(
                '🔍 ChatHandler: Skipping RAG - groupKey: %s, topic: %s, text empty: %s',
                $ragGroupKey ?? 'none',
                $topic,
                empty($message->getText()) ? 'yes' : 'no'
            ));
        }

        return ['context' => $ragContext, 'sources' => $ragSources];
    }

    /**
     * One knowledge search, by the turn's shared embedding when there is one.
     *
     * @param list<RagScope>|null            $scopes
     * @param \Closure(): ?array<int, float> $resolveSharedVector
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchByTurnVector(Message $message, ?string $groupKey, int $limit, float $minScore, ?array $scopes, \Closure $resolveSharedVector): array
    {
        $sharedVector = $resolveSharedVector();
        if (null !== $sharedVector) {
            // Phase 1a: reuse the shared embedding instead of having
            // VectorSearchService call ->embed() again.
            return $this->vectorSearchService->semanticSearchByVector(
                $message->getUserId(),
                $sharedVector,
                $groupKey,
                limit: $limit,
                minScore: $minScore,
                queryText: (string) $message->getText(),
                explicitScopes: $scopes,
            );
        }

        return $this->vectorSearchService->semanticSearch(
            $message->getText(),
            $message->getUserId(),
            $groupKey,
            limit: $limit,
            minScore: $minScore,
            explicitScopes: $scopes,
        );
    }
}
