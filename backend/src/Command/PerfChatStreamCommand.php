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
 * **DB side effects**: each iteration writes one inbound `Message` row
 * (and the chat pipeline persists at least one outbound assistant row),
 * all tagged with `BPROVIDX = 'PERF'`. By default the command deletes
 * those rows after the benchmark finishes so it leaves the DB clean —
 * use `--keep-data` if you want to inspect the intermediate state.
 * Even with cleanup, **don't run this against a live production DB**:
 * the inserts go through the regular pipeline, so embeddings and
 * memory-extraction worker jobs can fire as a side effect.
 *
 * Usage:
 *
 *     bin/console app:perf:chat-stream
 *     bin/console app:perf:chat-stream --user-id=1 --message="Tell me a story" --runs=5
 *     bin/console app:perf:chat-stream --keep-data   # leave PERF rows in place
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
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Number of iterations to average', '3')
            ->addOption('keep-data', null, InputOption::VALUE_NONE, 'Keep the BPROVIDX=PERF rows after the run instead of cleaning them up');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user-id');
        $messageText = (string) $input->getOption('message');
        $runs = max(1, (int) $input->getOption('runs'));
        $keepData = (bool) $input->getOption('keep-data');

        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            $io->error("User #{$userId} not found.");

            return Command::FAILURE;
        }

        $io->title('Synaplan: chat-stream perf benchmark');
        $io->text(sprintf('User: #%d (%s) | runs: %d', $userId, $user->getMail(), $runs));
        $io->note(
            'This command writes real Message rows (BPROVIDX=PERF) through the '
            .'production pipeline. Use against the test stack only; rows are '
            .'cleaned up at the end unless --keep-data is passed.'
        );

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

        if ($keepData) {
            $io->text('Skipping cleanup (--keep-data); BPROVIDX=PERF rows left in place.');

            return Command::SUCCESS;
        }

        $this->cleanupPerfRows($userId, $io);

        return Command::SUCCESS;
    }

    /**
     * Delete every Message row tagged `BPROVIDX = 'PERF'` for this user.
     *
     * Covers both the inbound rows we created in {@see self::execute()} and
     * any outbound assistant rows the streaming pipeline persisted in
     * response. We don't touch BMESSAGEMETA explicitly — Doctrine's cascade
     * + the FK on `BMESSAGEMETA.BMESSAGEID` keep it in sync.
     */
    private function cleanupPerfRows(int $userId, SymfonyStyle $io): void
    {
        try {
            $deleted = (int) $this->em->createQuery(
                'DELETE FROM '.Message::class.' m '
                .'WHERE m.userId = :uid AND m.providerIndex = :px'
            )
                ->setParameter('uid', $userId)
                ->setParameter('px', 'PERF')
                ->execute();

            if ($deleted > 0) {
                $io->text(sprintf('Cleaned up %d PERF Message row(s).', $deleted));
            }
        } catch (\Throwable $e) {
            $io->warning(sprintf(
                'Cleanup failed (%s) — re-run with --keep-data and remove rows manually.',
                $e->getMessage(),
            ));
        }
    }
}
