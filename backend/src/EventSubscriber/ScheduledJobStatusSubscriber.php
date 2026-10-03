<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Scheduler\ScheduledJobs;
use App\Service\Scheduler\ScheduledJobStatusStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records start and finish of scheduled commands. Redis trouble is logged and
 * swallowed: a status write must not change the job's own exit code.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onCommand')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminate')]
final readonly class ScheduledJobStatusSubscriber
{
    public function __construct(
        private ScheduledJobStatusStore $status,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->guard($event, 'start', function (string $command): void {
            $this->status->recordStarted($command, $this->clock->now()->getTimestamp());
        });
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->guard($event, 'finish', function (string $command) use ($event): void {
            $this->status->recordFinished($command, $this->clock->now()->getTimestamp(), $event->getExitCode());
        });
    }

    /**
     * @param \Closure(string): void $record
     */
    private function guard(ConsoleEvent $event, string $phase, \Closure $record): void
    {
        $command = $this->scheduledCommand($event);
        if (null === $command) {
            return;
        }

        try {
            $record($command);
        } catch (\Throwable $e) {
            try {
                $this->logger->warning(sprintf(
                    'Failed to record scheduler status for "%s" on %s: %s',
                    $command,
                    $phase,
                    $e->getMessage(),
                ));
            } catch (\Throwable) {
                // The job's exit code must not change because status recording failed.
            }
        }
    }

    private function scheduledCommand(ConsoleEvent $event): ?string
    {
        $name = $event->getCommand()?->getName();
        if (!is_string($name) || '' === $name || str_starts_with($name, 'app:scheduler:')) {
            return null;
        }

        if (!in_array($name, ScheduledJobs::all(), true)) {
            return null;
        }

        return $name;
    }
}
