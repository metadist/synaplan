<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

/**
 * A handler's request to the router to route this turn again, rather than a
 * reply to the user.
 *
 * Only the Phase 9 native tool-calling path produces one. The classifier
 * deferred the routing decision to the answering call, so that call can come
 * back with something other than an answer, and {@see \App\Service\Message\InferenceRouter}
 * — the one place that owns handler dispatch — acts on it:
 *
 *  - {@see self::handoff()}: the model called a hand-off tool. Its own reply
 *    (if any) is discarded and the turn is dispatched to the handed-off
 *    capability's handler instead.
 *  - {@see self::reclassify()}: the resolved chat model cannot do native tool
 *    calling at all, so the deferral was never honoured. Nothing was sent to
 *    a model; the turn goes back through the classifier with deferral
 *    switched off and lands on the ordinary AI-sorter path — the "Rückfall auf
 *    den Sorter-Pfad" the plan requires to stay possible at all times.
 *
 * Travels inside the handler result array under {@see self::RESULT_KEY} rather
 * than as a thrown exception: a hand-off is an ordinary, expected outcome of
 * the turn, and the streaming path has already sent SSE progress events by the
 * time it happens.
 */
final readonly class RoutingDirective
{
    public const RESULT_KEY = 'routing_directive';

    public const TYPE_HANDOFF = 'handoff';
    public const TYPE_RECLASSIFY = 'reclassify';

    /**
     * @param string                $type   one of the TYPE_* constants
     * @param string|null           $topic  target topic for TYPE_HANDOFF, null for TYPE_RECLASSIFY
     * @param array<string, string> $fields classification fields recovered from the tool call
     *                                      (e.g. `media_type`), already validated against
     *                                      {@see \App\Service\Message\Capability\SystemCapabilityRegistry}
     *                                      by {@see RoutingToolset::classificationFieldsFor()}
     */
    private function __construct(
        public string $type,
        public ?string $topic = null,
        public array $fields = [],
    ) {
    }

    /**
     * @param array<string, string> $fields
     */
    public static function handoff(string $topic, array $fields = []): self
    {
        return new self(self::TYPE_HANDOFF, $topic, $fields);
    }

    public static function reclassify(): self
    {
        return new self(self::TYPE_RECLASSIFY);
    }

    /**
     * The handler result carrying nothing but this directive.
     *
     * `content` is deliberately empty rather than absent: every caller of a
     * handler reads that key, and a directive means there is no reply text —
     * not that the handler misbehaved.
     *
     * @return array{content: string, metadata: array<string, mixed>, routing_directive: self}
     */
    public function toHandlerResult(): array
    {
        return [
            'content' => '',
            'metadata' => [],
            self::RESULT_KEY => $this,
        ];
    }

    /**
     * @param array<string, mixed> $handlerResult
     */
    public static function fromHandlerResult(array $handlerResult): ?self
    {
        $directive = $handlerResult[self::RESULT_KEY] ?? null;

        return $directive instanceof self ? $directive : null;
    }
}
