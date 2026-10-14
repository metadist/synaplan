<?php

declare(strict_types=1);

namespace App\Module;

use App\Bundle\BundleConfig;
use App\Module\Gate\ModuleGateConfig;
use App\Module\Sidecar\LiveVoiceModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Service\Compute\ComputeConfig;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\Plugin\PluginManager;

/**
 * Installed modules, plugins, file-work, and the build line.
 *
 * Also the env-only flags that describe whether optional infrastructure
 * (help, the memory service, realtime) is switched on.
 */
final readonly class ModulesRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private ModuleRegistry $modules,
        private ModuleGateConfig $moduleGate,
        private PluginManager $pluginManager,
        private ?ComputeConfig $computeConfig = null,
        private ?BundleConfig $bundleConfig = null,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $user = $context->user;
        $plugins = [];
        if (null !== $user) {
            foreach ($this->pluginManager->listInstalledPlugins($user->getId()) as $plugin) {
                $plugins[] = [
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'description' => $plugin->description,
                    'capabilities' => $plugin->capabilities,
                    'chatCommands' => $plugin->chatCommands,
                ];
            }
        }

        $realtimeEnabled = 'true' === ($_ENV['REALTIME_ENABLED'] ?? 'false');

        return [
            'voiceLive' => [
                'enabled' => $this->modules->get(LiveVoiceModule::ID)->isConfigured(),
            ],
            'plugins' => $plugins,
            'build' => [
                'version' => $_ENV['APP_VERSION'] ?? 'dev',
                'ip' => $this->internalIp(),
            ],
            'realtime' => [
                'enabled' => $realtimeEnabled,
                'wsUrl' => (string) ($_ENV['REALTIME_PUBLIC_WS_URL'] ?? ''),
            ],
            'modules' => $this->moduleStates(),
            'features' => [
                'help' => ($_ENV['FEATURE_HELP'] ?? 'false') === 'true',
                'memoryService' => !empty($_ENV['QDRANT_URL']),
                'bundleEnabled' => null !== $this->bundleConfig && $this->bundleConfig->isEnabled($user?->getId()),
                'officeConvertEnabled' => $this->modules->get(OfficeConvertModule::ID)->isConfigured(),
                'computeEnabled' => null !== $this->computeConfig && $this->computeConfig->isEnabled($user?->getId()),
                'computeWorkspacesEnabled' => null !== $this->computeConfig && $this->computeConfig->workspacesEnabled($user?->getId()),
            ],
        ];
    }

    /**
     * @return array<string, array{configured: bool, gated: bool}>
     */
    private function moduleStates(): array
    {
        $states = [];
        foreach ($this->modules->all() as $id => $module) {
            $states[$id] = [
                'configured' => $module->isConfigured(),
                'gated' => $this->moduleGate->isGated($id),
            ];
        }

        return $states;
    }

    private function internalIp(): string
    {
        $synDbHost = $_ENV['SYNDBHOST'] ?? '';
        if ('' !== $synDbHost && str_starts_with($synDbHost, '10.')) {
            return $synDbHost;
        }

        $hostname = gethostname();
        if ($hostname) {
            $ips = gethostbynamel($hostname);
            if ($ips) {
                foreach ($ips as $ip) {
                    if (str_starts_with($ip, '10.')) {
                        return $ip;
                    }
                }
            }
        }

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
        if (str_starts_with($serverAddr, '10.')) {
            return $serverAddr;
        }

        return 'dev';
    }
}
