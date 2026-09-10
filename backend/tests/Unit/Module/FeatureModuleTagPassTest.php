<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\DependencyInjection\FeatureModuleTagPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class FeatureModuleTagPassTest extends TestCase
{
    public function testRewritesTheTagWithTheIdConstantAsKey(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('module.alpha', (new Definition(AlphaModuleStub::class))->addTag(FeatureModuleTagPass::TAG));

        (new FeatureModuleTagPass())->process($container);

        $tags = $container->getDefinition('module.alpha')->getTag(FeatureModuleTagPass::TAG);
        $this->assertSame([[FeatureModuleTagPass::INDEX_ATTRIBUTE => 'alpha']], $tags);
    }

    public function testDuplicateIdFailsCompilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('module.alpha', (new Definition(AlphaModuleStub::class))->addTag(FeatureModuleTagPass::TAG));
        $container->setDefinition('module.alpha_again', (new Definition(AlphaAgainModuleStub::class))->addTag(FeatureModuleTagPass::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Feature module id "alpha" is declared twice');

        (new FeatureModuleTagPass())->process($container);
    }

    public function testMissingIdConstantFailsCompilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('module.nameless', (new Definition(NamelessModuleStub::class))->addTag(FeatureModuleTagPass::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must declare "public const ID"');

        (new FeatureModuleTagPass())->process($container);
    }

    public function testMalformedIdFailsCompilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('module.shouty', (new Definition(ShoutyModuleStub::class))->addTag(FeatureModuleTagPass::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be a lower-case snake_case string');

        (new FeatureModuleTagPass())->process($container);
    }

    public function testTaggedServiceThatIsNotAModuleFailsCompilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('not.a.module', (new Definition(\stdClass::class))->addTag(FeatureModuleTagPass::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement');

        (new FeatureModuleTagPass())->process($container);
    }

    public function testContainerWithoutModulesIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('unrelated', new Definition(\stdClass::class));

        (new FeatureModuleTagPass())->process($container);

        $this->assertSame([], $container->findTaggedServiceIds(FeatureModuleTagPass::TAG));
    }
}

/**
 * @internal test double
 */
abstract class ModuleStubBase implements FeatureModuleInterface
{
    public function labelKey(): string
    {
        return 'modules.'.$this->id().'.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy();
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function status(): ModuleStatus
    {
        return ModuleStatus::absent('stub');
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [];
    }

    public function docsAnchor(): string
    {
        return 'modules/'.$this->id();
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}

final class AlphaModuleStub extends ModuleStubBase
{
    public const ID = 'alpha';

    public function id(): string
    {
        return self::ID;
    }
}

final class AlphaAgainModuleStub extends ModuleStubBase
{
    public const ID = 'alpha';

    public function id(): string
    {
        return self::ID;
    }
}

final class NamelessModuleStub extends ModuleStubBase
{
    public function id(): string
    {
        return 'nameless';
    }
}

final class ShoutyModuleStub extends ModuleStubBase
{
    public const ID = 'SHOUTY';

    public function id(): string
    {
        return self::ID;
    }
}
