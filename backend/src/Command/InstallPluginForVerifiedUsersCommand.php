<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Plugin\PluginManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:plugin:install-verified-users',
    description: 'Installs a plugin for all active verified users',
)]
final class InstallPluginForVerifiedUsersCommand extends Command
{
    private const ACTIVE_LEVELS = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PluginManager $pluginManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin to install for active verified users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginName = (string) $input->getArgument('pluginName');

        $availablePlugins = array_map(
            static fn ($plugin) => $plugin->name,
            $this->pluginManager->listAvailablePlugins()
        );

        if (!in_array($pluginName, $availablePlugins, true)) {
            $io->error("Plugin '{$pluginName}' is not available in the central plugin repository.");

            return Command::FAILURE;
        }

        $userIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT BID
             FROM BUSER
             WHERE BUSERLEVEL IN (:levels)
               AND BEMAILVERIFIED = 1
             ORDER BY BID',
            ['levels' => self::ACTIVE_LEVELS],
            ['levels' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        if ([] === $userIds) {
            $io->warning('No active verified users found.');

            return Command::SUCCESS;
        }

        $installed = 0;
        $failed = 0;

        $io->progressStart(count($userIds));

        foreach ($userIds as $userId) {
            try {
                $this->pluginManager->installPlugin((int) $userId, $pluginName);
                ++$installed;
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning("Failed for user {$userId}: {$e->getMessage()}");
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($failed > 0) {
            $io->warning("Installed '{$pluginName}' for {$installed} users, {$failed} failed.");

            return Command::FAILURE;
        }

        $io->success("Installed '{$pluginName}' for {$installed} active verified users.");

        return Command::SUCCESS;
    }
}
