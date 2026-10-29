<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchRunNotFound;

/**
 * Passes the writer's words on to the run's events while the report is
 * being written, so the person reads the answer as it forms. Pieces are
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
    ) {
        $this->lastFlush = ($this->clock)();
    }

    public static function for(StageContext $ctx): self
    {
        return new self($ctx, static fn (): float => microtime(true));
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
            $this->ctx->events->append($this->ctx->state->ref->id, 'draft', ['text' => $text]);
        } catch (ResearchRunNotFound) {
            $this->closed = true;
        }
    }
}
