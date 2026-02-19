<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:model:disable',
    description: 'Remove AI models from the database',
)]
class ModelDisableCommand extends Command
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
                'Model keys to disable (e.g. groq:llama-3.3-70b-versatile ollama:bge-m3)')
            ->setHelp(
                "Remove one or more AI models from the database.\n\n".
                "Key format: service:providerId (or service:providerId:tag to target a specific variant)\n\n".
                'Run <info>app:model:list</info> to see all available models and their status.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modelKeys = $input->getArgument('models');
        $disabled = 0;
        $errors = false;

        foreach ($modelKeys as $key) {
            $models = ModelCatalog::find($key);

            if (empty($models)) {
                $io->warning("Unknown model key: $key");
                $errors = true;
                continue;
            }

            foreach ($models as $model) {
                ModelCatalog::remove($this->connection, $model);
                $io->writeln("  <info>Disabled</info> {$model['service']}: {$model['name']} ({$model['tag']})");
                ++$disabled;
            }
        }

        if ($disabled > 0) {
            $io->success("Disabled $disabled model(s)");
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
