<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Catalog;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DependencyInjection\ProviderCatalogPass;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Provider\CerebrasProvider;
use App\AI\Provider\Descriptor\TestProviderDescriptor;
use App\AI\Provider\Descriptor\XaiDescriptor;
use App\AI\Provider\ElevenLabsProvider;
use App\AI\Provider\OllamaProvider;
use App\AI\Provider\TestProvider;
use App\AI\Provider\XaiProvider;
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

    public function testADescribedAdapterGetsOneTagPerCapabilityKeyedByItsName(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(CerebrasProvider::class, (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG));

        (new ProviderCatalogPass())->process($container);

        $definition = $container->getDefinition(CerebrasProvider::class);
        self::assertSame([['key' => 'cerebras']], $definition->getTag('app.ai.chat'));
        self::assertSame([['key' => 'cerebras']], $definition->getTag('app.ai.vision'));
        self::assertSame([], $definition->getTag('app.ai.embedding'));
    }

    public function testASiblingDescriptorTagsTheAdapterItNamesAndATagIsNeverDoubled(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(XaiDescriptor::class, (new Definition(XaiDescriptor::class))->addTag(AsAiProvider::TAG));
        $container->setDefinition(XaiProvider::class, (new Definition(XaiProvider::class))->addTag('app.ai.chat', ['key' => 'xai']));

        (new ProviderCatalogPass())->process($container);

        $adapter = $container->getDefinition(XaiProvider::class);
        self::assertSame([['key' => 'xai']], $adapter->getTag('app.ai.chat'), 'the YAML tag is kept, not doubled');
        self::assertSame([['key' => 'xai']], $adapter->getTag('app.ai.video_generation'));
        self::assertSame([], $container->getDefinition(XaiDescriptor::class)->getTag('app.ai.chat'), 'the descriptor class carries no capability');
        self::assertSame([XaiDescriptor::class], $container->getDefinition(ProviderCatalog::class)->getArgument('$providerClasses'));
    }

    public function testAnAdapterRegisteredUnderAnotherServiceIdIsTaggedToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition('ai.cerebras', (new Definition(CerebrasProvider::class))->addTag(AsAiProvider::TAG));
        $container->setDefinition('ai.xai', new Definition(XaiProvider::class));
        $container->setDefinition(XaiDescriptor::class, (new Definition(XaiDescriptor::class))->addTag(AsAiProvider::TAG));

        (new ProviderCatalogPass())->process($container);

        self::assertSame([['key' => 'cerebras']], $container->getDefinition('ai.cerebras')->getTag('app.ai.chat'), 'an inline descriptor under its own id');
        self::assertSame([['key' => 'xai']], $container->getDefinition('ai.xai')->getTag('app.ai.chat'), 'a sibling resolves its adapter by class');
        self::assertFalse($container->hasDefinition(CerebrasProvider::class));
    }

    public function testAKeyOnlyProviderAndAMissingAdapterGetNoTags(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderCatalog::class, new Definition(ProviderCatalog::class, ['$providerClasses' => []]));
        $container->setDefinition(ElevenLabsProvider::class, (new Definition(ElevenLabsProvider::class))->addTag(AsAiProvider::TAG));
        // The test provider's adapter was removed by PerfTestProviderPass in production.
        $container->setDefinition(TestProviderDescriptor::class, (new Definition(TestProviderDescriptor::class))->addTag(AsAiProvider::TAG));

        (new ProviderCatalogPass())->process($container);

        self::assertSame([AsAiProvider::TAG], array_keys($container->getDefinition(ElevenLabsProvider::class)->getTags()), 'no capability, no capability tag');
        self::assertFalse($container->hasDefinition(TestProvider::class));
        self::assertSame([ElevenLabsProvider::class, TestProviderDescriptor::class], $container->getDefinition(ProviderCatalog::class)->getArgument('$providerClasses'));
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
