<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Model\PromptAiModelMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:config:migrate-prompt-models',
    description: 'Migrate legacy task-prompt aiModel metadata to per-user capability defaults (Release B).',
)]
final class MigratePromptModelsCommand extends Command
{
    public function __construct(
        private readonly PromptAiModelMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'apply',
            null,
            InputOption::VALUE_NONE,
            'Persist changes. Without this flag the command only reports what would change (dry run).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        if (!$apply) {
            $io->note('Dry run — pass --apply to persist migrations.');
        }

        $result = $this->migrator->migrate($apply);

        $io->table(
            ['Metric', 'Count'],
            [
                ['Scanned aiModel rows', (string) $result['scanned']],
                ['Would migrate / migrated', (string) $result['migrated']],
                ['Skipped', (string) $result['skipped']],
                ['Prompt aiModel cleared', (string) $result['cleared']],
            ]
        );

        if ([] !== $result['details']) {
            $rows = array_map(
                static fn (array $row): array => [
                    (string) $row['prompt_id'],
                    $row['topic'],
                    (string) $row['user_id'],
                    (string) $row['model_id'],
                    $row['capability'],
                    $row['action'],
                ],
                $result['details']
            );
            $io->section('Details');
            $io->table(['Prompt', 'Topic', 'User', 'Model', 'Capability', 'Action'], $rows);
        }

        $io->success($apply ? 'Migration complete.' : 'Dry run complete.');

        return Command::SUCCESS;
    }
}
