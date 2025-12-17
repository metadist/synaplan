<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InboundEmailHandlerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:process-mail-handlers',
    description: 'Process inbound email handlers (IMAP/POP3 to department routing)'
)]
class ProcessMailHandlersCommand extends Command
{
    public function __construct(
        private InboundEmailHandlerService $handlerService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch mode - process continuously')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Check interval in seconds (min 10)', 60);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = $input->getOption('watch');
        $interval = max(10, (int) $input->getOption('interval')); // Minimum 10 seconds

        // Prevent overlapping runs (TTL = 15 minutes, should be > than cronjob interval)
        $lock = $this->lockFactory->createLock('mail-handler-process', 900);

        if (!$lock->acquire()) {
            $message = 'Previous mail handler process is still running. Skipping this run to prevent overlap.';
            $io->warning($message);
            $this->logger->info($message);

            return Command::SUCCESS;
        }

        try {
            $io->title('Mail Handler Processing Service');
            $io->info('This service processes inbound emails and routes them to departments using AI.');

            if ($watch) {
                $io->note("Watch mode enabled. Checking every {$interval} seconds. Press CTRL+C to stop.");
                $io->newLine();

                // @phpstan-ignore-next-line (infinite loop is intentional for watch mode)
                while (true) {
                    $this->processHandlers($io);
                    sleep($interval);
                }
            } else {
                $this->processHandlers($io);
            }
        } finally {
            // Always release lock when done
            $lock->release();
        }

        return Command::SUCCESS;
    }

    private function processHandlers(SymfonyStyle $io): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $io->text("[$timestamp] Checking mail handlers...");

        try {
            $results = $this->handlerService->processAllHandlers();

            if (empty($results)) {
                $io->text('  → No active handlers to process.');
                $io->newLine();

                return;
            }

            $totalProcessed = 0;
            $totalErrors = 0;

            foreach ($results as $handlerId => $result) {
                if ($result['success']) {
                    if ($result['processed'] > 0) {
                        $io->success("Handler #{$handlerId}: Processed {$result['processed']} email(s)");
                        $totalProcessed += $result['processed'];
                    } else {
                        $io->text("  → Handler #{$handlerId}: No new emails");
                    }

                    if (!empty($result['errors'])) {
                        $io->warning("Handler #{$handlerId}: {$result['processed']} processed, ".count($result['errors']).' failed');
                        foreach ($result['errors'] as $error) {
                            $io->text("    • {$error}");
                        }
                        $totalErrors += count($result['errors']);
                    }
                } else {
                    $io->error("Handler #{$handlerId}: Failed to process");
                    foreach ($result['errors'] as $error) {
                        $io->text("    • {$error}");
                    }
                    ++$totalErrors;
                }
            }

            // Flush entity manager to persist lastChecked and status updates
            $this->em->flush();

            // Summary
            if ($totalProcessed > 0 || $totalErrors > 0) {
                $io->newLine();
                $io->section('Summary');
                $io->text("Total emails processed: {$totalProcessed}");
                if ($totalErrors > 0) {
                    $io->text("Total errors: {$totalErrors}");
                }
            }

            $io->newLine();
        } catch (\Exception $e) {
            $io->error('Failed to process handlers: '.$e->getMessage());
            $this->logger->error('Mail handler processing command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $io->newLine();
        }
    }
}
