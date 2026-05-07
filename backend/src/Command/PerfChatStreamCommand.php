<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\MessageProcessor;
use App\Service\PerfTimer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 4: end-to-end perf benchmark for the streaming chat pipeline.
 *
 * Runs the same `MessageProcessor::processStream()` path that the SSE
 * controller hits, using the test stub provider (configured in test env
 * via `DEFAULTMODEL.CHAT = -1`). Captures per-phase wall-clock timings
 * via the same {@see PerfTimer} that ships in the production `perf` SSE
 * event, and prints a sorted table.
 *
 * Wire into CI as a soft gate: track the per-phase numbers across builds
 * and warn (don't fail) when any phase regresses by more than 50 %.
 *
 * Usage:
 *
 *     bin/console app:perf:chat-stream
 *     bin/console app:perf:chat-stream --user-id=1 --message="Tell me a story" --runs=5
 */
#[AsCommand(
    name: 'app:perf:chat-stream',
    description: 'Benchmark the streaming chat pipeline phase-by-phase',
)]
final class PerfChatStreamCommand extends Command
{
    public function __construct(
        private readonly MessageProcessor $messageProcessor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID to run the bench as', '1')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Prompt text', 'Hello from the perf benchmark — please reply briefly.')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Number of iterations to average', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user-id');
        $messageText = (string) $input->getOption('message');
        $runs = max(1, (int) $input->getOption('runs'));

        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            $io->error("User #{$userId} not found.");

            return Command::FAILURE;
        }

        $io->title('Synaplan: chat-stream perf benchmark');
        $io->text(sprintf('User: #%d (%s) | runs: %d', $userId, $user->getMail(), $runs));

        // Aggregate phase totals across runs so we can print a mean.
        $aggregated = [];
        $totals = [];

        for ($i = 1; $i <= $runs; ++$i) {
            $perfTimer = new PerfTimer();

            $chat = $this->em->getRepository(Chat::class)->findOneBy(['userId' => $userId]);
            if (!$chat) {
                $io->error('No chat found for user — create one in the UI first.');

                return Command::FAILURE;
            }

            $message = new Message();
            $message->setUserId($userId);
            $message->setChat($chat);
            $message->setTrackingId(time() + $i);
            $message->setProviderIndex('PERF');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('PERF');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en');
            $message->setText($messageText);
            $message->setDirection('IN');
            $message->setStatus('processing');

            $this->em->persist($message);
            $this->em->flush();

            // Stream callback discards chunks — we only care about phase
            // timing, not the response body.
            $streamCallback = static function (): void {};

            $start = microtime(true);
            $result = $this->messageProcessor->processStream(
                $message,
                $streamCallback,
                null,
                ['perf_timer' => $perfTimer, 'reasoning' => false],
            );
            $totalMs = (microtime(true) - $start) * 1000.0;
            $totals[] = $totalMs;

            if (!($result['success'] ?? false)) {
                $io->warning(sprintf('Run %d failed: %s', $i, (string) ($result['error'] ?? 'unknown')));
                continue;
            }

            foreach ($perfTimer->totals() as $phase => $ms) {
                $aggregated[$phase][] = $ms;
            }

            $io->writeln(sprintf('  run %d: %s ms total', $i, number_format($totalMs, 1)));
        }

        if (empty($aggregated)) {
            $io->error('No successful runs.');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($aggregated as $phase => $samples) {
            sort($samples);
            $mean = array_sum($samples) / count($samples);
            $median = $samples[(int) (count($samples) / 2)];
            $rows[] = [
                $phase,
                number_format($mean, 1),
                number_format($median, 1),
                number_format(min($samples), 1),
                number_format(max($samples), 1),
                count($samples),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => (float) str_replace(',', '', $b[1]) <=> (float) str_replace(',', '', $a[1]));

        $io->section('Phase breakdown (ms)');
        $io->table(['phase', 'mean', 'median', 'min', 'max', 'n'], $rows);

        sort($totals);
        $totalMean = array_sum($totals) / count($totals);
        $io->success(sprintf(
            'Total: mean %s ms, median %s ms, min %s ms, max %s ms across %d runs',
            number_format($totalMean, 1),
            number_format($totals[(int) (count($totals) / 2)], 1),
            number_format(min($totals), 1),
            number_format(max($totals), 1),
            count($totals),
        ));

        return Command::SUCCESS;
    }
}
