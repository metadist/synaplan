<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\Service\Plugin\PluginManager;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

/**
 * Opt-in: only chat commands that declare `tool.sideEffect` enter the registry.
 */
final readonly class PluginCommandToolSource implements ToolSourceInterface
{
    public function __construct(
        private PluginManager $pluginManager,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Plugin;
    }

    public function describe(int $userId, array $context = []): array
    {
        if ($userId < 1) {
            return [];
        }

        $descriptors = [];
        foreach ($this->pluginManager->listInstalledPlugins($userId) as $manifest) {
            foreach ($manifest->chatCommands as $command) {
                $sideEffectRaw = $command['tool']['sideEffect'] ?? null;
                $sideEffect = is_string($sideEffectRaw) ? SideEffect::tryFrom($sideEffectRaw) : null;
                if (null === $sideEffect) {
                    continue;
                }
                $name = 'plugin:'.$manifest->name.':'.$command['command'];
                $descriptors[] = new ToolDescriptor(
                    name: $name,
                    title: $command['command'],
                    description: (string) ($command['description'] ?? ''),
                    inputSchema: ['type' => 'object', 'properties' => []],
                    sideEffect: $sideEffect,
                    source: ToolSource::Plugin,
                    ownerId: $userId,
                    meta: [
                        'pluginId' => $manifest->name,
                        'command' => $command['command'],
                        'endpoint' => $command['endpoint'],
                    ],
                );
            }
        }

        return $descriptors;
    }
}
