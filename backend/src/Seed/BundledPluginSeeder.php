<?php

declare(strict_types=1);

namespace App\Seed;

use App\Repository\ConfigRepository;
use App\Service\Plugin\BundledPlugins;
use App\Service\Plugin\PluginManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Installs Synaform and Synamail for verified accounts that do not have them yet.
 *
 * `app:seed` runs on every container start, after demo fixtures, so a fresh
 * database and an existing install both pick this up. A migration cannot:
 * it runs before fixture users exist, and it cannot create the per-user
 * plugin symlink the menu reads.
 *
 * Insert-if-missing only. An account whose `enabled` row is `0` is left
 * off. A row of `1` with a missing or broken symlink is installed again so
 * the menu and the API agree. A database reset that keeps the upload files
 * (the symlink is still there, the `enabled` row is gone) is installed again.
 * One account's failure is logged and the remaining accounts still run.
 * The step then fails, so the next start retries what did not finish.
 */
#[AsTaggedItem(priority: 15)]
final readonly class BundledPluginSeeder implements SeederInterface
{
    private const ACTIVE_LEVELS = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'];

    public function __construct(
        private Connection $connection,
        private ConfigRepository $config,
        private PluginManager $plugins,
        private LoggerInterface $logger,
    ) {
    }

    public function label(): string
    {
        return 'bundled-plugins';
    }

    public function description(): string
    {
        return 'Synaform and Synamail for verified accounts that do not have them yet';
    }

    public function seed(): SeedResult
    {
        $available = [];
        foreach ($this->plugins->listAvailablePlugins() as $plugin) {
            $available[$plugin->name] = true;
        }

        $inserted = 0;
        $skipped = 0;
        $userIds = null;
        $failures = [];

        foreach (BundledPlugins::NAMES as $name) {
            if (!isset($available[$name])) {
                $this->logger->warning('Bundled plugin is not in this image, skipping install', [
                    'plugin' => $name,
                ]);
                continue;
            }

            $userIds ??= $this->verifiedUserIds();
            foreach ($userIds as $userId) {
                if (!$this->needsInstall($userId, $name)) {
                    ++$skipped;
                    continue;
                }

                try {
                    $this->plugins->installPlugin($userId, $name);
                    ++$inserted;
                } catch (\Throwable $e) {
                    $failures[] = $userId.'/'.$name;
                    $this->logger->error('Bundled plugin install failed', [
                        'user_id' => $userId,
                        'plugin' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ([] !== $failures) {
            throw new \RuntimeException(sprintf('Bundled plugin install failed for %s', implode(', ', $failures)));
        }

        return new SeedResult('bundled_plugins', inserted: $inserted, skipped: $skipped);
    }

    /**
     * @return list<int>
     */
    private function verifiedUserIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT BID
             FROM BUSER
             WHERE BUSERLEVEL IN (:levels)
               AND BEMAILVERIFIED = 1
             ORDER BY BID',
            ['levels' => self::ACTIVE_LEVELS],
            ['levels' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    private function needsInstall(int $userId, string $pluginName): bool
    {
        $enabled = $this->config->getValue($userId, $this->plugins->configGroup($pluginName), 'enabled');
        if ('0' === $enabled) {
            return false;
        }

        return '1' !== $enabled || !$this->plugins->isInstalled($userId, $pluginName);
    }
}
