<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Catalog;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DependencyInjection\ProviderCatalogPass;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Provider\CerebrasProvider;
use App\AI\Provider\OllamaProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ProviderCatalogPassTest extends TestCase
{
    public function testTaggedProviderClassesReachTheCatalogAndAbstractOnesDoNot(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(CerebrasProvider::class, (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG));
        $container->setDefinition('ollama.alias', (new Definition(OllamaProvider::class))->addTag(AsAiProvider::TAG));
        $container->setDefinition('abstract.provider', (new Definition(CerebrasProvider::class))->setAbstract(true)->addTag(AsAiProvider::TAG));

        (new ProviderCatalogPass())->process($container);

        self::assertSame(
            [CerebrasProvider::class, OllamaProvider::class],
            $container->getDefinition(ProviderCatalog::class)->getArgument('$providerClasses'),
        );
    }

    public function testACapabilityTaggedProviderWithoutADescriptorFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(CerebrasProvider::class, (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG));
        // Tagged in YAML only, no attribute, no descriptor: the catalog must refuse.
        $container->setDefinition('acme.provider', (new Definition(\stdClass::class))->addTag('app.ai.chat', ['key' => 'acme']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('tagged app.ai.chat with key "acme" but no class describes a provider of that name');
        (new ProviderCatalogPass())->process($container);
    }

    public function testACapabilityTagWithoutAKeyFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        // The locator would index it by service id, bypassing the described-name check.
        $container->setDefinition('acme.provider', (new Definition(\stdClass::class))->addTag('app.ai.chat'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Service "acme.provider" is tagged app.ai.chat without a key');
        (new ProviderCatalogPass())->process($container);
    }

    public function testACapabilityTagWhoseKeyIsDescribedPasses(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(CerebrasProvider::class, (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG)->addTag('app.ai.chat', ['key' => 'cerebras']));

        (new ProviderCatalogPass())->process($container);

        self::assertSame([CerebrasProvider::class], $container->getDefinition(ProviderCatalog::class)->getArgument('$providerClasses'));
    }

    public function testATaggedClassWithoutADescriptorFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(\stdClass::class, (new Definition(\stdClass::class))->addTag(AsAiProvider::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement');
        (new ProviderCatalogPass())->process($container);
    }

    public function testTheAttributeConfiguratorRefusesAClassThatDoesNotDescribeItself(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('carries #[AsAiProvider] but does not implement');
        ProviderCatalogPass::configure(new ChildDefinition('parent'), new AsAiProvider(), new \ReflectionClass(\stdClass::class));
    }

    public function testTheAttributeConfiguratorTagsADescribingClass(): void
    {
        $definition = new ChildDefinition('parent');
        ProviderCatalogPass::configure($definition, new AsAiProvider(), new \ReflectionClass(CerebrasProvider::class));

        self::assertTrue($definition->hasTag(AsAiProvider::TAG));
    }

    public function testWithoutACatalogDefinitionThePassIsANoOp(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(CerebrasProvider::class, (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG));

        (new ProviderCatalogPass())->process($container);

        self::assertFalse($container->hasDefinition(ProviderCatalog::class));
    }
}
