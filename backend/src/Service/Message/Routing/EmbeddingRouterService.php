<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase 8 embedding-router cascade layer.
 *
 * Embeds an incoming utterance with the same bge-m3 model used for RAG
 * ({@see AiFacade::embed()}) and compares it via cosine similarity against
 * pre-computed anchor embeddings — the example utterances declared on
 * {@see \App\Service\Message\Capability\SystemCapability} for the four
 * SYSTEM topics, synced into the `routing_anchors` Qdrant collection by
 * `app:routing:sync-anchors`.
 *
 * Deliberately narrow in scope, matching the plan's "Bewusst eng gefasst":
 *   - Only the four SYSTEM topics have curated anchors. A user-defined topic
 *     (from BPROMPTS) has none, so the closest anchor is always one of the
 *     four system topics — never a false-positive match onto a custom topic.
 *   - This service only ANSWERS "what's the closest anchor and how close is
 *     it". The confidence-threshold decision of whether that closeness is
 *     good enough to skip the AI sorter belongs to the caller
 *     ({@see \App\Service\Message\MessageClassifier}), driven by
 *     {@see EmbeddingRouterConfig::getConfidenceThreshold()} so it stays
 *     calibratable via `app:sort-eval --cascade` without a code change.
 *   - A Qdrant/embedding failure (unreachable, collection not yet synced)
 *     degrades to "no match" rather than an exception — the AI sorter is
 *     always a safe fallback, so this layer must never be able to break a
 *     chat turn, only skip a round-trip for it.
 */
final readonly class EmbeddingRouterService
{
    /**
     * How many candidate anchors to fetch per lookup. Needs to be small
     * multiple of 1 (not just the top hit) so the caller can report the
     * runner-up topic as a {@see RoutingDecision::$discardedAlternatives}
     * entry, the same transparency the AI-sorting layer already gives.
     */
    private const CANDIDATE_LIMIT = 5;

    public function __construct(
        private AiFacade $aiFacade,
        private QdrantClientInterface $qdrant,
        private LoggerInterface $logger,
        private SystemCapabilityRegistry $capabilityRegistry,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private ModelConfigService $modelConfigService,
    ) {
    }

    /**
     * Find the closest routing anchor for `$text`, if any anchors exist.
     *
     * Returns null when there is nothing to compare against (empty text,
     * embedding failure, or the anchors collection is empty/unsynced) —
     * NOT when the match is merely below a confidence threshold. Threshold
     * evaluation is the caller's job (see class docblock): this method
     * always returns the single best-scoring anchor it found, together with
     * runner-up topics for the discarded-alternatives log trail.
     */
    public function findClosestAnchor(string $text, ?int $userId = null): ?EmbeddingRouterMatch
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return null;
        }

        try {
            $embedResult = $this->aiFacade->embed($trimmed, $userId);
            $vector = $embedResult['embedding'];
        } catch (\Throwable $e) {
            $this->logger->warning('EmbeddingRouterService: embedding failed, deferring to AI sorter', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->recordEmbeddingUsage($embedResult, $trimmed, $userId);

        if ([] === $vector) {
            return null;
        }

        $hits = $this->qdrant->searchRoutingAnchors($vector, self::CANDIDATE_LIMIT);
        if ([] === $hits) {
            return null;
        }

        $best = $hits[0];
        $bestTopic = $this->validTopic($best['payload']['topic'] ?? null);
        if (null === $bestTopic) {
            // A stale anchor (renamed capability) or a tampered payload must
            // never inject a topic into routing, no matter how high its score.
            // The AI sorter is the safe fallback.
            $this->logger->warning('EmbeddingRouterService: anchor carries an unknown topic, deferring to AI sorter', [
                'payload_topic' => $best['payload']['topic'] ?? null,
                'score' => $best['score'] ?? null,
            ]);

            return null;
        }

        $discardedAlternatives = [];
        foreach (array_slice($hits, 1) as $hit) {
            $topic = $this->validTopic($hit['payload']['topic'] ?? null);
            if (null === $topic || $topic === $bestTopic) {
                // Same-topic runner-ups add no routing information (they'd
                // only confirm the already-chosen topic), so only distinct
                // topics are worth surfacing as a discarded alternative.
                continue;
            }
            $discardedAlternatives[] = ['topic' => $topic, 'score' => (float) $hit['score']];
        }

        return new EmbeddingRouterMatch($bestTopic, (float) $best['score'], $discardedAlternatives);
    }

    /**
     * A payload topic is only usable if the capability registry — the source
     * of truth `app:routing:sync-anchors` builds the collection from — still
     * declares it.
     */
    private function validTopic(mixed $topic): ?string
    {
        if (!\is_string($topic) || '' === $topic) {
            return null;
        }

        return null !== $this->capabilityRegistry->byTopic($topic) ? $topic : null;
    }

    /**
     * Book the embedding against the user's quota and cost meter, exactly as
     * every other {@see AiFacade::embed()} call site does. Without this, an
     * enabled embedding router would spend one embedding per message invisibly.
     *
     * @param array<string, mixed> $embedResult
     */
    private function recordEmbeddingUsage(array $embedResult, string $text, ?int $userId): void
    {
        if (null === $userId || $userId <= 0) {
            return;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (null === $user) {
            return;
        }

        // The embed call above deliberately passes no model, so it lands on the
        // default embedding provider — the same one `app:routing:sync-anchors`
        // built the anchors with. VECTORIZE names that model in every supported
        // setup and is what CostCalculationService prices against; if an
        // operator points VECTORIZE elsewhere the cost row is mislabelled, but
        // routing itself is unaffected.
        $modelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);

        $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
            'usage' => $embedResult['usage'] ?? ['prompt_tokens' => 0, 'total_tokens' => 0],
            'provider' => (null !== $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null) ?? 'unknown',
            'model' => (null !== $modelId ? $this->modelConfigService->getModelName($modelId) : null) ?? 'unknown',
            'model_id' => $modelId,
            'input_text' => $text,
            'source' => 'EMBEDDING_ROUTER',
        ]);
    }
}
