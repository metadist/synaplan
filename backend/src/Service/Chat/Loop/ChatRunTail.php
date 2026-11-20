<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Exception\StreamCancelledException;
use App\Service\Media\MediaCancellationStore;
use App\Service\Message\ProgressCallback;
use Psr\Clock\ClockInterface;

/**
 * Follows a run the open stream handed over to, until it ends, waits for the
 * person, or the request has followed it long enough. Each event goes to the
 * stream as a `chat_run` status. A Stop in the chat stops the run.
 *
 * The run belongs to the request that started it, so nothing here reads a
 * run for another person: the stream knows only its own run id.
 */
final readonly class ChatRunTail
{
    public const STATUS = 'chat_run';

    /** As long as one unit may hold its claim: after that the run goes on and posts its answer itself. */
    public const FOLLOW_SECONDS = ChatRunUnitHandler::LEASE_MS / 1000;

    /** The longest a quiet run blocks the read before Stop and the deadline are checked again. */
    private const WAIT_MS = 1000;

    /** A quiet model call must not let a proxy close the stream. */
    private const KEEPALIVE_SECONDS = 15;

    public function __construct(
        private ChatRunStore $store,
        private ChatRunEvents $events,
        private ChatRunStarter $starter,
        private MediaCancellationStore $cancellations,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return ChatRunState|null the run as it was when the stream stopped following it
     *
     * @throws StreamCancelledException when the person pressed Stop
     */
    public function follow(string $runId, string $trackId, ?callable $progress): ?ChatRunState
    {
        $after = 0;
        $now = $this->clock->now()->getTimestamp();
        $until = $now + (int) self::FOLLOW_SECONDS;
        $spoke = $now;
        while (true) {
            $state = $this->store->load($runId);
            foreach ($this->events->replay($runId, $after) as $event) {
                $after = $event['seq'];
                $spoke = $this->clock->now()->getTimestamp();
                ProgressCallback::notify($progress, self::STATUS, $event['event'], [...$event['data'], 'run_id' => $runId, 'event' => $event['event']]);
            }
            if (null === $state || $state->isTerminal() || ChatRunStatus::Waiting === $state->status) {
                return $state;
            }
            if ('' !== $trackId && $this->cancellations->isCancelled($trackId)) {
                $this->starter->stop($runId);

                throw new StreamCancelledException('Stream cancelled by user');
            }
            $now = $this->clock->now()->getTimestamp();
            if ($now >= $until) {
                return $state;
            }
            if ($now - $spoke >= self::KEEPALIVE_SECONDS) {
                $spoke = $now;
                ProgressCallback::notify($progress, self::STATUS, 'working', ['run_id' => $runId, 'event' => 'working']);
            }
            $this->events->await($runId, $after, self::WAIT_MS);
        }
    }
}
