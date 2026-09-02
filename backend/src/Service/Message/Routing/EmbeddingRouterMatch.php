<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

/**
 * Result of a confident {@see EmbeddingRouterService::match()} lookup.
 *
 * `$discardedAlternatives` carries the runner-up anchors (different topic
 * than `$topic`) so a {@see RoutingDecision} built from this match can show,
 * in the log, exactly what the embedding router considered and rejected —
 * the same transparency {@see RoutingDecision} already gives the AI sorter.
 */
final readonly class EmbeddingRouterMatch
{
    /**
     * @param list<array{topic: string, score: float}> $discardedAlternatives
     */
    public function __construct(
        public string $topic,
        public float $score,
        public array $discardedAlternatives = [],
    ) {
    }
}
