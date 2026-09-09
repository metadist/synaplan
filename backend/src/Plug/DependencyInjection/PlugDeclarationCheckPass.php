<?php

declare(strict_types=1);

namespace App\Plug\DependencyInjection;

use App\Plug\PlugPorts;
use App\Service\Plugin\PluginManifest;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails container compilation when a plugin class is tagged as a plug adapter
 * but the plugin's manifest does not declare it in `provides.plugs`.
 *
 * The kernel autoconfigures every class under a plugin's `backend/`, so a class
 * that merely implements a plug interface is tagged `app.plug.*` by accident.
 * This turns that into an explicit, auditable contract: an adapter reaches a
 * registry only when the manifest declares its port and class. Core adapters
 * (`App\Plug\**`) live under no plugin namespace and are exempt.
 */
final class PlugDeclarationCheckPass implements CompilerPassInterface
{
    /**
     * @param list<array{dir: string, namespace: string}> $plugins
     */
    public function __construct(private readonly array $plugins)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        if ([] === $this->plugins) {
            return;
        }

        [$declaredPortsByClass, $pluginIdByPrefix] = $this->readDeclarations();
        if ([] === $pluginIdByPrefix) {
            return;
        }

        foreach (PlugPorts::portByTag() as $tag => $port) {
            foreach (array_keys($container->findTaggedServiceIds($tag)) as $serviceId) {
                $class = $this->classOf($container, $serviceId);
                $prefix = $this->matchingPluginPrefix($class, array_keys($pluginIdByPrefix));
                if (null === $prefix) {
                    // Core adapter (App\Plug\**) or a non-plugin service — exempt.
                    continue;
                }

                if (!isset($declaredPortsByClass[$class]) || !in_array($port, $declaredPortsByClass[$class], true)) {
                    throw new \LogicException(sprintf('Plugin "%s" registers %s for port %s but does not declare it in manifest.json provides.plugs', $pluginIdByPrefix[$prefix], $class, $port));
                }
            }
        }
    }

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>}
     *                                                                         [ class => declared ports, namespace-prefix => plugin id ]
     */
    private function readDeclarations(): array
    {
        $declaredPortsByClass = [];
        $pluginIdByPrefix = [];

        foreach ($this->plugins as $plugin) {
            $manifestPath = $plugin['dir'].'/manifest.json';
            $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
            $data = false === $raw ? null : json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            $prefix = rtrim($plugin['namespace'], '\\').'\\';
            $pluginIdByPrefix[$prefix] = (string) ($data['id'] ?? $data['name'] ?? basename($plugin['dir']));

            // Throws InvalidPluginManifestException on a malformed provides.plugs —
            // a bad declaration should fail the boot, not be silently ignored.
            foreach (PluginManifest::fromArray($data)->plugs as $plug) {
                $declaredPortsByClass[$plug['class']][] = $plug['port'];
            }
        }

        return [$declaredPortsByClass, $pluginIdByPrefix];
    }

    private function classOf(ContainerBuilder $container, string $serviceId): string
    {
        if ($container->hasDefinition($serviceId)) {
            $class = $container->getDefinition($serviceId)->getClass();
            if (null !== $class) {
                return $class;
            }
        }

        // Autoregistered plugin services use the FQCN as the service id.
        return $serviceId;
    }

    /**
     * @param list<string> $prefixes
     */
    private function matchingPluginPrefix(string $class, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }
}
