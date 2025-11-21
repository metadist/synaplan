<?php

namespace App\Command;

use App\Service\InboundEmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-emails',
    description: 'Process incoming emails from Mailhog and forward to webhook'
)]
class ProcessEmailsCommand extends Command
{
    public function __construct(
        private InboundEmailService $inboundEmailService,
        private LoggerInterface $logger,
        private string $appUrl
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for new emails continuously')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Check interval in seconds (for watch mode)', 10)
            ->addOption('keep', 'k', InputOption::VALUE_NONE, 'Keep emails in Mailhog after processing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = $input->getOption('watch');
        $interval = (int) $input->getOption('interval');
        $deleteAfter = !$input->getOption('keep');

        $webhookUrl = rtrim($this->appUrl, '/') . '/api/v1/webhooks/email';

        $io->title('Email Processing Service');
        $io->info("Webhook URL: {$webhookUrl}");
        $io->info("Delete after processing: " . ($deleteAfter ? 'Yes' : 'No'));

        if ($watch) {
            $io->note("Watch mode enabled. Checking every {$interval} seconds. Press CTRL+C to stop.");
            
            while (true) {
                $this->processEmails($io, $webhookUrl, $deleteAfter);
                sleep($interval);
            }
        } else {
            $this->processEmails($io, $webhookUrl, $deleteAfter);
        }

        return Command::SUCCESS;
    }

    private function processEmails(SymfonyStyle $io, string $webhookUrl, bool $deleteAfter): void
    {
        $io->text('[' . date('Y-m-d H:i:s') . '] Checking for new emails...');

        try {
            $results = $this->inboundEmailService->processMailhogEmails($webhookUrl, $deleteAfter);

            if ($results['total'] === 0) {
                $io->text('No emails found.');
                return;
            }

            $io->success(sprintf(
                'Processed %d/%d emails successfully',
                $results['processed'],
                $results['total']
            ));

            if ($results['failed'] > 0) {
                $io->warning(sprintf('%d emails failed to process', $results['failed']));
                foreach ($results['errors'] as $error) {
                    $io->text("  - {$error['email']}: {$error['error']}");
                }
            }
        } catch (\Exception $e) {
            $io->error('Failed to process emails: ' . $e->getMessage());
            $this->logger->error('Email processing command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}

