<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use Psr\Log\LoggerInterface;

/**
 * Phase 8 of the cascade (EMBEDDING_ROUTER, off by default), after every
 * deterministic layer (overrides, tool commands, attachments) and before
 * the AI sorter. Embeds the message with the same bge-m3 model used for RAG
 * and matches it via cosine similarity against pre-computed example
 * utterance anchors for the four SYSTEM topics (general, mediamaker,
 * officemaker, docsummary) stored in Qdrant — see EmbeddingRouterService and
 * the `app:routing:sync-anchors` command that (re)populates the anchors from
 * SystemCapabilityRegistry::exampleUtterances. User-defined topics have no
 * curated anchors, so a confident match is always one of the four system
 * topics; anything else escalates to the AI sorter.
 *
 * Like the fast path, a confident match short-circuits the sorter round-trip
 * entirely — unlike the fast path this is not restricted to `general`.
 * Extraction of the sorter's topic-specific votes (BMEDIA, BRESOLUTION,
 * BINPUTMODE) is intentionally NOT attempted here: MediaPromptExtractor
 * already re-derives BMEDIA from the message text via its own dedicated AI
 * call whenever the classification omits it, so a mediamaker match degrades
 * gracefully instead of needing those votes up front.
 */
final readonly class EmbeddingRoute
{
    public function __construct(
        private EmbeddingRouterService $embeddingRouter,
        private EmbeddingRouterConfig $embeddingRouterConfig,
        private TopicIntentRule $topicIntents,
        private SelfAwareQuestion $selfAware,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $needsTheSorter a produce-a-file turn or a spoken answer, which only the sorter and the planner deliver
     */
    public function match(Message $message, string $text, ?int $overrideModelId, bool $needsTheSorter, bool $forceWebSearch): EmbeddingVerdict
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();

        if (null === $overrideModelId
            && !empty($text)
            && !$needsTheSorter
            && $this->embeddingRouterConfig->isEnabled($userId)
            && !$this->selfAware->matches($text, $userId)
        ) {
            $embeddingMatch = $this->embeddingRouter->findClosestAnchor($text, $userId);

            if (null !== $embeddingMatch && $embeddingMatch->score >= $this->embeddingRouterConfig->getConfidenceThreshold()) {
                $confidentLanguage = ConfidentLanguage::of($message, $text);

                if (null !== $confidentLanguage) {
                    $this->logger->info('MessageClassifier: Embedding-router match (skipped AI sorter)', [
                        'message_id' => $messageId,
                        'topic' => $embeddingMatch->topic,
                        'confidence' => $embeddingMatch->score,
                        'language' => $confidentLanguage,
                    ]);

                    $embeddingDecision = new RoutingDecision(
                        RoutingLayer::EmbeddingRouter,
                        $embeddingMatch->topic,
                        confidence: $embeddingMatch->score,
                        discardedAlternatives: array_map(
                            static fn (array $alternative): string => sprintf('%s (%.3f)', $alternative['topic'], $alternative['score']),
                            $embeddingMatch->discardedAlternatives
                        ),
                    );

                    // `web_search` is intentionally null here for the same
                    // reason as the fast-path: no AI-sorter round-trip means
                    // no BWEBSEARCH vote, and under the "trust the model"
                    // policy a missing vote means no search.
                    return new EmbeddingVerdict(SlashCommandRoute::withForceWebSearch(array_merge([
                        'topic' => $embeddingMatch->topic,
                        'language' => $confidentLanguage,
                        'web_search' => null,
                        'source' => $embeddingDecision->toClassificationSource(),
                        'skip_sorting' => true,
                        'intent' => $this->topicIntents->intentFor($embeddingMatch->topic),
                        'model_id' => null,
                        'provider' => null,
                        'model_name' => null,
                    ], $embeddingDecision->toClassificationFields()), $forceWebSearch));
                }

                $this->logger->info('MessageClassifier: Embedding-router match declined (language ambiguous) — deferring to AI sorter', [
                    'message_id' => $messageId,
                    'topic' => $embeddingMatch->topic,
                    'confidence' => $embeddingMatch->score,
                ]);

                return new EmbeddingVerdict(null, true);
            }
        }

        return new EmbeddingVerdict();
    }
}
