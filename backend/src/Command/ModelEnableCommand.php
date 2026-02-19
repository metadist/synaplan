<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:model:enable',
    description: 'Add AI models to the database',
)]
class ModelEnableCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('models', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Model keys to enable (e.g. groq:llama-3.3-70b-versatile ollama:bge-m3)')
            ->addOption('system', null, InputOption::VALUE_NONE,
                'Mark enabled models as system models (locked, users cannot change)')
            ->setHelp(
                "Enable one or more AI models from the built-in catalog.\n\n".
                "Key format: service:providerId (or service:providerId:tag to target a specific variant)\n\n".
                "Use <info>--system</info> to lock models so users cannot change them.\n\n".
                'Run <info>app:model:list</info> to see all available models and their status.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modelKeys = $input->getArgument('models');
        $system = $input->getOption('system');
        $enabled = 0;
        $errors = false;

        foreach ($modelKeys as $key) {
            $models = ModelCatalog::find($key);

            if (empty($models)) {
                $io->warning("Unknown model key: $key");
                $errors = true;
                continue;
            }

            foreach ($models as $model) {
                ModelCatalog::upsert($this->connection, $model, $system);
                $label = $system ? 'Enabled (system)' : 'Enabled';
                $io->writeln("  <info>$label</info> {$model['service']}: {$model['name']} ({$model['tag']})");
                ++$enabled;
            }
        }

        if ($enabled > 0) {
            $io->success("Enabled $enabled model(s)");
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
