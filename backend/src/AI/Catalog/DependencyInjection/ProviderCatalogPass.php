<?php

declare(strict_types=1);

namespace App\AI\Catalog\DependencyInjection;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hands {@see ProviderCatalog} the class of every service tagged
 * {@see AsAiProvider::TAG}, and refuses a container in which a provider is
 * registered for a capability (`app.ai.<capability>` tag, however it got
 * there) without a descriptor of the same name. The marker tag comes from
 * the `#[AsAiProvider]` attribute through {@see self::configure()}, which
 * `App\Kernel::build()` registers for autoconfiguration; a class that
 * carries the attribute without describing itself fails the build too.
 */
final class ProviderCatalogPass implements CompilerPassInterface
{
    /**
     * The autoconfiguration callback for `#[AsAiProvider]`.
     */
    public static function configure(ChildDefinition $definition, AsAiProvider $attribute, \ReflectionClass $reflector): void
    {
        if (!$reflector->implementsInterface(DescribesProviderInterface::class)) {
            throw new \LogicException(sprintf('%s carries #[AsAiProvider] but does not implement %s.', $reflector->getName(), DescribesProviderInterface::class));
        }
        $definition->addTag(AsAiProvider::TAG);
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ProviderCatalog::class)) {
            return;
        }

        $classes = $this->describedClasses($container);
        $this->assertEveryRegisteredProviderIsDescribed($container, $classes);

        $container->getDefinition(ProviderCatalog::class)->setArgument('$providerClasses', $classes);
    }

    /**
     * @return list<class-string<DescribesProviderInterface>>
     */
    private function describedClasses(ContainerBuilder $container): array
    {
        $classes = [];
        foreach (array_keys($container->findTaggedServiceIds(AsAiProvider::TAG)) as $id) {
            $definition = $container->getDefinition($id);
            if ($definition->isAbstract()) {
                continue;
            }
            $class = $definition->getClass() ?? $id;
            if (!is_a($class, DescribesProviderInterface::class, true)) {
                throw new \LogicException(sprintf('Service "%s" is tagged %s but %s does not implement %s.', $id, AsAiProvider::TAG, $class, DescribesProviderInterface::class));
            }
            $classes[$class] = true;
        }

        return array_keys($classes);
    }

    /**
     * A provider tagged in `services.yaml` without the attribute would
     * otherwise escape the catalog silently.
     *
     * @param list<class-string<DescribesProviderInterface>> $classes
     */
    private function assertEveryRegisteredProviderIsDescribed(ContainerBuilder $container, array $classes): void
    {
        $described = [];
        foreach ($classes as $class) {
            $described[$class::descriptor()->name] = $class;
        }

        foreach (ProviderCapability::cases() as $capability) {
            foreach ($container->findTaggedServiceIds($capability->tag()) as $id => $tags) {
                foreach ($tags as $attributes) {
                    $key = (string) ($attributes['key'] ?? '');
                    if ('' !== $key && !isset($described[$key])) {
                        throw new \LogicException(sprintf('Service "%s" is tagged %s with key "%s" but no class describes a provider of that name. Add #[AsAiProvider] and descriptor() to it.', $id, $capability->tag(), $key));
                    }
                }
            }
        }
    }
}
