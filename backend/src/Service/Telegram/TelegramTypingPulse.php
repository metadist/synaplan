<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Clock\ClockInterface;

/**
 * Keeps Telegram's "typing…" status visible for a whole AI turn.
 *
 * `sendChatAction` lasts at most five seconds, or until the bot sends a
 * message. One call at the start of a long answer disappears while the model
 * is still working, so the action is repeated on a clock, never with a sleep.
 * A revoked token or a blocked bot stops the pulse; Telegram clears the
 * status itself once the reply goes out.
 */
final class TelegramTypingPulse
{
    private const MIN_INTERVAL_SECONDS = 4;

    private ?int $lastBeatAt = null;

    private bool $stopped = false;

    /**
     * @param \Closure(): void                         $send
     * @param \Closure(TelegramChannelException): void $onFailure
     */
    public function __construct(
        private ClockInterface $clock,
        private \Closure $send,
        private \Closure $onFailure,
    ) {
    }

    public function beat(): void
    {
        if ($this->stopped) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        if (null !== $this->lastBeatAt && ($now - $this->lastBeatAt) < self::MIN_INTERVAL_SECONDS) {
            return;
        }

        try {
            ($this->send)();
            $this->lastBeatAt = $now;
        } catch (TelegramChannelException $e) {
            ($this->onFailure)($e);
            if (in_array($e->errorCode, [
                TelegramChannelException::TOKEN_REVOKED,
                TelegramChannelException::BOT_BLOCKED,
            ], true)) {
                $this->stopped = true;
            }
        }
    }
}
