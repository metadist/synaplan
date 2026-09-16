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
    name: 'app:model:disable',
    description: 'Disable AI models (kept in the database, hidden from users)',
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
            ->addArgument('models', InputArgument::IS_ARRAY,
                'Model keys to disable (e.g. groq:qwen/qwen3.6-27b ollama:bge-m3)')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Disable every catalog model of a provider (e.g. --provider openai). Repeatable.')
            ->setHelp(
                "Disable one or more AI models: they stay in the database (message history\n".
                "references them) but are deactivated and hidden from every model picker.\n".
                "The deactivation survives re-seeding at container start.\n".
                "Re-enable any time with <info>app:model:enable</info>.\n\n".
                "Key format: service:providerId (or service:providerId:tag to target a specific variant)\n\n".
                "Use <info>--provider <name></info> to disable every catalog model of a provider at once.\n\n".
                'Run <info>app:model:list</info> to see all available models and their status, '.
                'or <info>app:provider:list</info> for the provider overview.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modelKeys = $input->getArgument('models');
        $providers = $input->getOption('provider');

        if ([] === $modelKeys && [] === $providers) {
            $io->error('Provide model keys and/or --provider <name>. Run app:model:list to see the catalog.');

            return Command::INVALID;
        }

        $errors = false;

        // Collect first, then write: dedupe by BID so an explicit key and a
        // --provider covering the same model do not disable it twice.
        $selected = [];

        foreach ($modelKeys as $key) {
            $models = ModelCatalog::find($key);

            if (empty($models)) {
                $io->warning("Unknown model key: $key");
                $errors = true;
                continue;
            }

            foreach ($models as $model) {
                $selected[$model['id']] = $model;
            }
        }

        foreach ($providers as $provider) {
            $models = ModelCatalog::findByService($provider);

            if (empty($models)) {
                $io->warning(sprintf(
                    'Unknown provider: %s. Known providers: %s',
                    $provider,
                    implode(', ', array_keys(ModelCatalog::serviceNames()))
                ));
                $errors = true;
                continue;
            }

            foreach ($models as $model) {
                $selected[$model['id']] = $model;
            }
        }

        $disabled = 0;

        foreach ($selected as $model) {
            ModelCatalog::disable($this->connection, $model);
            $io->writeln("  <info>Disabled</info> {$model['service']}: {$model['name']} ({$model['tag']})");
            ++$disabled;
        }

        if ($disabled > 0) {
            $io->success("Disabled $disabled model(s) — rows kept, re-enable with app:model:enable");
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
