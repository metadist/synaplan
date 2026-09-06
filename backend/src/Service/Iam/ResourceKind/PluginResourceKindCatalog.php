<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Repository\PluginDataRepository;
use App\Service\Plugin\InvalidPluginManifestException;
use App\Service\Plugin\PluginManager;

/**
 * Resolves plugin-declared kinds from loaded manifests.
 */
final readonly class PluginResourceKindCatalog
{
    public function __construct(
        private PluginManager $pluginManager,
        private PluginDataRepository $pluginDataRepository,
    ) {
    }

    public function get(string $key): ?ShareableResourceKindInterface
    {
        foreach ($this->declarations() as $decl) {
            if ($decl['key'] === $key) {
                return new PluginResourceKind(
                    $decl['key'],
                    $decl['dataType'],
                    $decl['permissions'],
                    $this->pluginDataRepository,
                );
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    /**
     * @return list<array{key: string, dataType: string, permissions: list<\App\Service\Iam\Permission>}>
     */
    private function declarations(): array
    {
        $out = [];
        try {
            $manifests = $this->pluginManager->listAvailablePlugins();
        } catch (InvalidPluginManifestException) {
            return [];
        }
        foreach ($manifests as $manifest) {
            foreach ($manifest->resourceKinds as $decl) {
                $out[] = $decl;
            }
        }

        return $out;
    }
}
