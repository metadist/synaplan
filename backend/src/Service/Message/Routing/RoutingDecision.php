<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

/**
 * Typed outcome of a single routing decision.
 *
 * Replaces the bare `source` string as the only trace of *how* a topic was
 * chosen. The gap this closes: {@see \App\Service\Message\MessageSorter::classify()}
 * could silently fall back to `topic: general` on a JSON parse failure, or
 * keep an AI-hallucinated topic that server-side validation had to correct —
 * and nothing in the returned array told a caller apart from a genuine,
 * confident classification; both produced `source: ai_sorting`. `$confidence`
 * and `$fallbackReason` make that distinction explicit; `$discardedAlternatives`
 * records what was considered and rejected en route to `$topic` (e.g. the
 * out-of-enum BTOPIC the AI actually returned).
 *
 * `$classification['source']` / `['topic']` are still populated FROM this
 * object ({@see self::toClassificationSource()}), but the string values stay
 * byte-identical to what the pipeline emitted before this class existed:
 * {@see \App\Service\Multitask\TaskPlanExecutor} gates on those strings and
 * must not see a behavior change from this refactor alone.
 */
final readonly class RoutingDecision
{
    /**
     * @param list<string> $discardedAlternatives Topics considered en route to $topic and rejected
     *                                            (e.g. an out-of-enum BTOPIC the AI returned before
     *                                            server-side validation corrected it)
     * @param string|null  $cost                  Charged cost of the AI call that produced this decision
     *                                            ({@see \App\Service\Usage\RecordedUsage::$chargedCost}),
     *                                            null for deterministic (non-AI) layers
     */
    public function __construct(
        public RoutingLayer $layer,
        public string $topic,
        public float $confidence,
        public array $discardedAlternatives = [],
        public ?string $cost = null,
        public ?string $fallbackReason = null,
    ) {
    }

    /**
     * A deterministic, non-AI decision (override, tool command, attachment
     * rule, fast-path heuristic match, or the sorter's internal rule-based
     * shortcut): always full confidence, never a fallback.
     */
    public static function deterministic(RoutingLayer $layer, string $topic): self
    {
        return new self($layer, $topic, confidence: 1.0);
    }

    /**
     * An AI-sorting call that did NOT complete cleanly — JSON parse failure,
     * missing sorting prompt, or a provider error swallowed by the sorter's
     * catch-all. `$topic` is whatever safe default the caller fell back to
     * (today always `general`).
     */
    public static function fallback(string $topic, string $reason): self
    {
        return new self(RoutingLayer::AiSorting, $topic, confidence: 0.0, fallbackReason: $reason);
    }

    public function isFallback(): bool
    {
        return null !== $this->fallbackReason;
    }

    /**
     * The exact `source` string the classification pipeline has always
     * emitted for this layer — see {@see RoutingLayer}.
     */
    public function toClassificationSource(): string
    {
        return $this->layer->value;
    }

    /**
     * Primitive, snapshot/log-safe fields to merge into the legacy
     * `$classification` / sorter-result array. Deliberately not the object
     * itself — routing snapshots, Discord notifications and plan persistence
     * all expect primitives, not value objects.
     *
     * @return array{routing_confidence: float, routing_fallback_reason: string|null, routing_discarded_alternatives: list<string>}
     */
    public function toClassificationFields(): array
    {
        return [
            'routing_confidence' => $this->confidence,
            'routing_fallback_reason' => $this->fallbackReason,
            'routing_discarded_alternatives' => $this->discardedAlternatives,
        ];
    }
}
