<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchRunNotFound;

/**
 * Passes a model's words on to the run's events while it writes them: the
 * writer's report (`draft`) or the lead's note before its tool calls
 * (`thinking`), so the person reads them as they form. Pieces are
 * gathered until there is enough for a line or a moment has passed, which
 * keeps the event log to a few dozen frames per report. An expired run
 * swallows the rest: the report itself is kept by the stage, not by the
 * stream.
 */
final class DraftStream
{
    private const FLUSH_CHARS = 160;
    private const FLUSH_SECONDS = 0.7;

    private string $pending = '';

    private float $lastFlush;

    private bool $closed = false;

    /**
     * @param \Closure(): float $clock
     */
    public function __construct(
        private readonly StageContext $ctx,
        private readonly \Closure $clock,
        private readonly string $event = 'draft',
    ) {
        $this->lastFlush = ($this->clock)();
    }

    public static function for(StageContext $ctx, string $event = 'draft'): self
    {
        return new self($ctx, static fn (): float => microtime(true), $event);
    }

    /** The listener a model call takes. */
    public function listener(): \Closure
    {
        return function (string $text): void {
            $this->add($text);
        };
    }

    public function add(string $text): void
    {
        if ($this->closed || '' === $text) {
            return;
        }
        $this->pending .= $text;
        $now = ($this->clock)();
        if (mb_strlen($this->pending) >= self::FLUSH_CHARS || $now - $this->lastFlush >= self::FLUSH_SECONDS) {
            $this->flush($now);
        }
    }

    /** Sends what is still waiting. Called once the model has finished. */
    public function finish(): void
    {
        if ('' !== $this->pending) {
            $this->flush(($this->clock)());
        }
    }

    private function flush(float $now): void
    {
        $text = $this->pending;
        $this->pending = '';
        $this->lastFlush = $now;

        try {
            $this->ctx->events->append($this->ctx->state->ref->id, $this->event, ['text' => $text]);
        } catch (ResearchRunNotFound) {
            $this->closed = true;
        }
    }
}
