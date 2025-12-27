<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:plugin:install',
    description: 'Installs a plugin for a specific user',
)]
class InstallPluginCommand extends Command
{
    public function __construct(
        private PluginManager $pluginManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('userId', InputArgument::REQUIRED, 'The ID of the user')
            ->addArgument('pluginName', InputArgument::REQUIRED, 'The name of the plugin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getArgument('userId');
        $pluginName = $input->getArgument('pluginName');

        try {
            $this->pluginManager->installPlugin($userId, $pluginName);
            $io->success("Plugin '$pluginName' installed successfully for user $userId.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to install plugin: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

