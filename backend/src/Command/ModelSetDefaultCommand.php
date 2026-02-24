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
    name: 'app:model:set-default',
    description: 'Set a model as the default for one or more capabilities',
)]
class ModelSetDefaultCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('model', InputArgument::REQUIRED,
                'Model key (e.g. groq:llama-3.3-70b-versatile, ollama:bge-m3)')
            ->addArgument('capabilities', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Capabilities to set this model as default for (e.g. chat vectorize)')
            ->setHelp(
                "Set a model as the default for one or more capabilities.\n\n".
                'Valid capabilities: '.implode(', ', array_map('strtolower', array_keys(ModelCatalog::CAPABILITY_TAGS)))."\n\n".
                "Example: <info>app:model:set-default ollama:bge-m3 vectorize</info>\n".
                "Example: <info>app:model:set-default groq:openai/gpt-oss-120b chat tools sort summarize</info>\n\n".
                'Run <info>app:model:list</info> to see all available models.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modelKey = $input->getArgument('model');
        $capabilities = $input->getArgument('capabilities');

        $models = ModelCatalog::find($modelKey);

        if (empty($models)) {
            $io->error("Unknown model key: $modelKey");

            return Command::FAILURE;
        }

        $errors = false;

        foreach ($capabilities as $capability) {
            $capUpper = strtoupper($capability);

            if (!isset(ModelCatalog::CAPABILITY_TAGS[$capUpper])) {
                $io->warning("Unknown capability: $capability (valid: ".implode(', ', array_map('strtolower', array_keys(ModelCatalog::CAPABILITY_TAGS))).')');
                $errors = true;
                continue;
            }

            // Find a model matching the required tag for this capability
            $requiredTag = ModelCatalog::CAPABILITY_TAGS[$capUpper];
            $model = null;
            foreach ($models as $candidate) {
                if (strtolower($candidate['tag']) === $requiredTag) {
                    $model = $candidate;
                    break;
                }
            }

            if (!$model) {
                $io->warning("Model '$modelKey' (tag: {$models[0]['tag']}) is incompatible with capability $capability (requires tag: $requiredTag)");
                $errors = true;
                continue;
            }

            $modelId = $model['id'];

            $this->connection->executeStatement(
                'DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?',
                ['DEFAULTMODEL', $capUpper]
            );

            $this->connection->executeStatement(
                'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, ?, ?, ?)',
                ['DEFAULTMODEL', $capUpper, (string) $modelId]
            );

            $io->writeln("  <info>Set default</info> $capability → {$model['service']}: {$model['name']} (id=$modelId)");
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
