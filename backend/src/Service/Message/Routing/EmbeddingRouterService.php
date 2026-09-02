<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\AI\Service\AiFacade;
use App\Service\VectorSearch\QdrantClientInterface;
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

        if ([] === $vector) {
            return null;
        }

        $hits = $this->qdrant->searchRoutingAnchors($vector, self::CANDIDATE_LIMIT);
        if ([] === $hits) {
            return null;
        }

        $best = $hits[0];
        $bestTopic = (string) ($best['payload']['topic'] ?? '');
        if ('' === $bestTopic) {
            // Malformed/orphaned anchor payload — never trust an anchor
            // without a topic, no matter how high its score.
            return null;
        }

        $discardedAlternatives = [];
        foreach (array_slice($hits, 1) as $hit) {
            $topic = (string) ($hit['payload']['topic'] ?? '');
            if ('' === $topic || $topic === $bestTopic) {
                // Same-topic runner-ups add no routing information (they'd
                // only confirm the already-chosen topic), so only distinct
                // topics are worth surfacing as a discarded alternative.
                continue;
            }
            $discardedAlternatives[] = ['topic' => $topic, 'score' => (float) $hit['score']];
        }

        return new EmbeddingRouterMatch($bestTopic, (float) $best['score'], $discardedAlternatives);
    }
}
