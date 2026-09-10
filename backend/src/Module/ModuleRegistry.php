<?php

declare(strict_types=1);

namespace App\Module;

use App\Module\Contract\FeatureModuleInterface;
use App\Module\DependencyInjection\FeatureModuleTagPass;
use App\Module\Exception\ModuleNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The one list of optional features (feature modules master plan §4.1).
 *
 * Backed by a lazy service locator keyed by module id (see
 * FeatureModuleTagPass): asking for ids or for one module never instantiates
 * the others. `all()` does instantiate every descriptor — descriptors are
 * cheap by contract, the expensive part (health probes) only happens when a
 * caller asks for `status()`.
 */
final class ModuleRegistry
{
    /**
     * @param ServiceProviderInterface<FeatureModuleInterface> $modules
     */
    public function __construct(
        #[AutowireLocator(FeatureModuleTagPass::TAG, indexAttribute: FeatureModuleTagPass::INDEX_ATTRIBUTE)]
        private readonly ServiceProviderInterface $modules,
    ) {
    }

    /**
     * @return list<string> module ids, sorted
     */
    public function ids(): array
    {
        $ids = array_keys($this->modules->getProvidedServices());
        sort($ids);

        return $ids;
    }

    public function has(string $id): bool
    {
        return $this->modules->has($id);
    }

    public function get(string $id): FeatureModuleInterface
    {
        if (!$this->modules->has($id)) {
            throw ModuleNotFoundException::forId($id, $this->ids());
        }

        return $this->modules->get($id);
    }

    /**
     * @return array<string, FeatureModuleInterface> keyed by id, sorted by id
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->ids() as $id) {
            $all[$id] = $this->modules->get($id);
        }

        return $all;
    }

    /**
     * @return array<string, FeatureModuleInterface>
     */
    public function configured(): array
    {
        return array_filter($this->all(), static fn (FeatureModuleInterface $module): bool => $module->isConfigured());
    }

    /**
     * @return array<string, FeatureModuleInterface>
     */
    public function absent(): array
    {
        return array_filter($this->all(), static fn (FeatureModuleInterface $module): bool => !$module->isConfigured());
    }

    /**
     * The module that owns the given route name, if any. An exact entry beats a
     * `prefix*` entry; among prefixes the longest wins, so a specific module can
     * sit under a broader one.
     */
    public function forRoute(string $routeName): ?FeatureModuleInterface
    {
        $best = null;
        $bestScore = -1;

        foreach ($this->all() as $module) {
            foreach ($module->routeNames() as $pattern) {
                $score = self::routeMatchScore($pattern, $routeName);
                if ($score > $bestScore) {
                    $best = $module;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /**
     * -1 = no match; exact matches score above every prefix match.
     */
    private static function routeMatchScore(string $pattern, string $routeName): int
    {
        if ('' === $pattern || '*' === $pattern) {
            return -1;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return str_starts_with($routeName, $prefix) ? strlen($prefix) : -1;
        }

        return $pattern === $routeName ? PHP_INT_MAX : -1;
    }
}
