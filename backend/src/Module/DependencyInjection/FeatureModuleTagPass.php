<?php

declare(strict_types=1);

namespace App\Module\DependencyInjection;

use App\Module\Contract\FeatureModuleInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Indexes every `app.feature_module` service by its `ID` constant and refuses
 * a container in which two descriptors claim the same id.
 *
 * The kernel tags each FeatureModuleInterface implementation by
 * autoconfiguration; this pass rewrites that tag as
 * `{ name: app.feature_module, key: <ID> }` so ModuleRegistry's
 * `#[AutowireLocator(..., indexAttribute: 'key')]` is keyed by module id
 * without instantiating anything. The id lives in exactly one place — the
 * class constant — and a descriptor without it fails compilation, so the
 * mistake surfaces in `lint:container`, never in production.
 */
final class FeatureModuleTagPass implements CompilerPassInterface
{
    public const TAG = 'app.feature_module';
    public const INDEX_ATTRIBUTE = 'key';
    public const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, string> $serviceIdByModuleId */
        $serviceIdByModuleId = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $definition = $container->findDefinition($serviceId);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!is_string($class) || !class_exists($class)) {
                throw new \LogicException(sprintf('Feature module service "%s" has no resolvable class.', $serviceId));
            }
            if (!is_subclass_of($class, FeatureModuleInterface::class)) {
                throw new \LogicException(sprintf('Service "%s" is tagged %s but %s does not implement %s.', $serviceId, self::TAG, $class, FeatureModuleInterface::class));
            }

            $moduleId = $this->readId($class);
            if (isset($serviceIdByModuleId[$moduleId])) {
                throw new \LogicException(sprintf('Feature module id "%s" is declared twice: by %s and %s.', $moduleId, $serviceIdByModuleId[$moduleId], $serviceId));
            }
            $serviceIdByModuleId[$moduleId] = $serviceId;

            $definition->clearTag(self::TAG);
            $definition->addTag(self::TAG, [self::INDEX_ATTRIBUTE => $moduleId]);
        }
    }

    /**
     * @param class-string<FeatureModuleInterface> $class
     */
    private function readId(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasConstant('ID')) {
            throw new \LogicException(sprintf('Feature module %s must declare "public const ID" with its module id.', $class));
        }

        $id = $reflection->getConstant('ID');
        if (!is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new \LogicException(sprintf('Feature module %s::ID must be a lower-case snake_case string, got %s.', $class, var_export($id, true)));
        }

        return $id;
    }
}
