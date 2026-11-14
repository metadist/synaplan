<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Chat\Run\PumpChatRunner;
use App\Service\Chat\Run\StreamJobGuard;
use App\Service\Chat\Run\StreamRedis;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Holds provider streams for turns the web request handed over.
 *
 * One process claims jobs, runs the chat turn, and writes the client's
 * frames to the run stream. SIGTERM stops new claims and lets the current
 * turn finish. The switch stays off until an operator turns it on.
 */
#[AsCommand(name: 'app:stream-pump', description: 'Read queued chat turns and write their frames outside the web request')]
final class StreamPumpCommand extends Command
{
    private bool $draining = false;

    public function __construct(
        private readonly StreamRedis $redis,
        private readonly PumpChatRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $incarnation = Uuid::v4()->toRfc4122();
        $server = getenv('SYNAPLAN_SERVER_ID');
        $serverId = is_string($server) && '' !== $server ? $server : (gethostname() ?: 'pump');
        $this->listenForStop();
        $this->redis->ensureGroup();
        $this->redis->reap(time());
        $output->writeln('Stream pump '.$incarnation.' started.');

        while (!$this->draining) {
            $this->redis->beat($incarnation, $serverId);
            $claimed = $this->redis->claim($incarnation);
            if (null === $claimed) {
                $this->redis->reap(time());
                usleep(50_000);

                continue;
            }
            $body = $this->redis->takeBody($claimed['job']);
            $this->redis->acknowledge($claimed['id']);
            if (!is_array($body)) {
                if ('' !== $claimed['run']) {
                    $this->redis->failRun($claimed['run'], 'queued', \App\Service\Chat\Run\PumpFrames::DID_NOT_START);
                }

                continue;
            }
            try {
                StreamJobGuard::reject($body);
            } catch (\InvalidArgumentException) {
                $this->logger->warning('Stream pump dropped a job that carried a provider key', [
                    'run_id' => $claimed['run'],
                ]);

                continue;
            }
            if ('chat_stream' === ($body['channel'] ?? null)) {
                $this->runner->run($body, $incarnation);
            }
        }

        $output->writeln('Stream pump '.$incarnation.' stopped.');

        return Command::SUCCESS;
    }

    private function listenForStop(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->draining = true;
        };
        pcntl_signal(\SIGTERM, $stop);
        pcntl_signal(\SIGINT, $stop);
    }
}
