<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installs a curated set of default plugins for newly created real users.
 */
final readonly class DefaultUserPluginProvisioner
{
    /**
     * @param string[] $defaultPlugins
     */
    public function __construct(
        private PluginManager $pluginManager,
        private LoggerInterface $logger,
        #[Autowire('%default_user_plugins%')]
        private array $defaultPlugins,
    ) {
    }

    public function provisionNewUser(User $user): void
    {
        $userId = $user->getId();
        if ($userId === null || empty($this->defaultPlugins)) {
            return;
        }

        $availablePlugins = [];
        foreach ($this->pluginManager->listAvailablePlugins() as $plugin) {
            $availablePlugins[$plugin->name] = true;
        }

        foreach (array_unique($this->defaultPlugins) as $pluginName) {
            if (!is_string($pluginName) || $pluginName === '') {
                continue;
            }

            if (!isset($availablePlugins[$pluginName])) {
                $this->logger->debug('Skipping default plugin provisioning because plugin is not available', [
                    'user_id' => $userId,
                    'plugin' => $pluginName,
                ]);

                continue;
            }

            try {
                $this->pluginManager->installPlugin($userId, $pluginName);
            } catch (\Throwable $e) {
                // Do not fail user creation if optional plugin provisioning fails.
                $this->logger->error('Failed to provision default plugin for new user', [
                    'user_id' => $userId,
                    'plugin' => $pluginName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
