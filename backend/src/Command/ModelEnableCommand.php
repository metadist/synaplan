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
    description: 'Enable AI models from the built-in catalog',
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
            ->addArgument('models', InputArgument::IS_ARRAY,
                'Model keys to enable (e.g. groq:qwen/qwen3.6-27b ollama:bge-m3)')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Enable every catalog model of a provider (e.g. --provider groq). Repeatable.')
            ->addOption('system', null, InputOption::VALUE_NONE,
                'Mark enabled models as system models (locked, users cannot change)')
            ->setHelp(
                "Enable one or more AI models from the built-in catalog.\n\n".
                "Key format: service:providerId (or service:providerId:tag to target a specific variant)\n\n".
                "Use <info>--provider <name></info> to enable every catalog model of a provider at once.\n\n".
                "A model already in the database gets its visibility flags restored to the\n".
                "catalog values; admin edits to prices or names are left untouched.\n".
                "Retired models are skipped — the upstream provider no longer serves them.\n\n".
                "Use <info>--system</info> to lock models so users cannot change them\n".
                "(applies when the model is first added).\n\n".
                'Run <info>app:model:list</info> to see all available models and their status, '.
                'or <info>app:provider:list</info> for the provider overview.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modelKeys = $input->getArgument('models');
        $providers = $input->getOption('provider');
        $system = $input->getOption('system');

        if ([] === $modelKeys && [] === $providers) {
            $io->error('Provide model keys and/or --provider <name>. Run app:model:list to see the catalog.');

            return Command::INVALID;
        }

        $errors = false;

        // Collect first, then write: dedupe by BID so an explicit key and a
        // --provider covering the same model do not enable it twice.
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

        $enabled = 0;
        $skippedRetired = 0;

        foreach ($selected as $model) {
            if (ModelCatalog::isRetired($model['id'])) {
                $io->writeln("  <comment>Skipped (retired)</comment> {$model['service']}: {$model['name']} ({$model['tag']})");
                ++$skippedRetired;
                continue;
            }

            ModelCatalog::enable($this->connection, $model, $system);
            $label = $system ? 'Enabled (system)' : 'Enabled';
            $io->writeln("  <info>$label</info> {$model['service']}: {$model['name']} ({$model['tag']})");
            ++$enabled;
        }

        if ($skippedRetired > 0) {
            $io->note("Skipped $skippedRetired retired model(s) — the upstream provider no longer serves them.");
        }

        if ($enabled > 0) {
            $io->success("Enabled $enabled model(s)");
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
