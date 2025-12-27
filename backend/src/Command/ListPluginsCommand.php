<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:plugin:list',
    description: 'Lists all available plugins',
)]
class ListPluginsCommand extends Command
{
    public function __construct(
        private PluginManager $pluginManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plugins = $this->pluginManager->listAvailablePlugins();

        if (empty($plugins)) {
            $io->warning('No plugins found in the central repository.');

            return Command::SUCCESS;
        }

        $io->title('Available Plugins');
        $rows = [];
        foreach ($plugins as $plugin) {
            $rows[] = [
                $plugin->name,
                $plugin->version,
                $plugin->description,
                implode(', ', $plugin->capabilities),
            ];
        }

        $io->table(['Name', 'Version', 'Description', 'Capabilities'], $rows);

        return Command::SUCCESS;
    }
}
