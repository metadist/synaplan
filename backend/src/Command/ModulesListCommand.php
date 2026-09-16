<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\ModuleRegistry;
use App\Module\ModuleStatusPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:modules:list',
    description: 'Show the optional feature modules of this installation and whether each is configured',
)]
final class ModulesListCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleStatusPresenter $presenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the module list as JSON (same shape as the feature-status API)')
            ->addOption('assert-none-configured', null, InputOption::VALUE_NONE,
                'Exit with status 1 when any module is configured — used by the minimal CI lane to prove the core stack carries no optional feature')
            ->setHelp(
                "A feature module is an optional part of Synaplan (a sidecar such as Tika or\n".
                "Collabora, an AI provider such as Higgsfield, billing, the WhatsApp channel)\n".
                "that the platform must run without. Each module declares which environment\n".
                "variables configure it; <info>configured</info> means that declaration is\n".
                "satisfied, <info>healthy</info> means the configured service also answered.\n\n".
                'Absent modules are never probed, so this command is safe on a minimal stack.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->presenter->rows();
        $configuredIds = array_keys($this->modules->configured());

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $io->table(
                ['Module', 'State', 'Configured by', 'Message'],
                array_map(static fn (array $row): array => [
                    $row['id'],
                    self::stateLabel($row['state']),
                    implode(', ', $row['configured_by']['env']),
                    $row['message'],
                ], $rows),
            );
            $io->text(sprintf('%d module(s), %d configured.', count($rows), count($configuredIds)));
        }

        if ((bool) $input->getOption('assert-none-configured') && [] !== $configuredIds) {
            $io->error(sprintf('Expected no configured feature module, found: %s', implode(', ', $configuredIds)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'available' => '<info>available</info>',
            'needs_setup' => '<comment>needs setup</comment>',
            default => 'absent',
        };
    }
}
