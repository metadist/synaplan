<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * Passes the text of one model call to the run's event log while it is
 * written, so a stream following the run shows it word by word.
 *
 * Deltas are gathered into small pieces to keep the log short. What was
 * passed on is added to `$state->said`: the answer stored at the end starts
 * with exactly what the person already saw, so a reload shows the same text.
 */
final class ChatRunSpeaker
{
    /** Characters gathered before a piece goes to the log; a line break sends it at once. */
    public const PIECE = 32;

    /** The most a run says; beyond it nothing more is streamed or kept. */
    public const MAX_SAID = 100_000;

    private string $buffer = '';

    private bool $spoke = false;

    public function __construct(
        private readonly ChatRunEvents $events,
        private readonly ChatRunState $state,
    ) {
    }

    public function __invoke(string $delta): void
    {
        if ('' === $delta) {
            return;
        }
        $line = str_contains($delta, "\n");
        if (!$this->spoke) {
            $this->spoke = true;
            $said = $this->state->said;
            // A new step continues below what the run already said.
            if ('' !== $said && !str_ends_with($said, "\n\n")) {
                $delta = (str_ends_with($said, "\n") ? "\n" : "\n\n").$delta;
            }
        }
        $this->buffer .= $delta;
        if ($line || mb_strlen($this->buffer) >= self::PIECE) {
            $this->flush();
        }
    }

    /** Sends what is left; call it once the model call returned or failed. */
    public function close(): void
    {
        $this->flush();
    }

    /** Caps a text the run says without a model call, such as the first answer made in the request. */
    public static function cap(string $text): string
    {
        return mb_substr($text, 0, self::MAX_SAID);
    }

    private function flush(): void
    {
        if ('' === $this->buffer) {
            return;
        }
        $room = self::MAX_SAID - mb_strlen($this->state->said);
        $piece = $room > 0 ? mb_substr($this->buffer, 0, $room) : '';
        $this->buffer = '';
        if ('' === $piece) {
            return;
        }
        $this->state->said .= $piece;
        $this->events->append($this->state->runId, ChatRunEvents::TEXT, ['text' => $piece]);
    }
}
