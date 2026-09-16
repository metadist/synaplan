<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Exception\ModuleNotFoundException;
use App\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ModuleRegistryTest extends TestCase
{
    public function testIdsAreSortedAndDoNotInstantiateModules(): void
    {
        $instantiated = 0;
        $registry = $this->registry([
            'zeta' => function () use (&$instantiated): FeatureModuleInterface {
                ++$instantiated;

                return $this->module('zeta', true);
            },
            'alpha' => function () use (&$instantiated): FeatureModuleInterface {
                ++$instantiated;

                return $this->module('alpha', false);
            },
        ]);

        $this->assertSame(['alpha', 'zeta'], $registry->ids());
        $this->assertTrue($registry->has('alpha'));
        $this->assertFalse($registry->has('omega'));
        $this->assertSame(0, $instantiated, 'ids()/has() must be answered from the locator without creating descriptors');
    }

    public function testGetReturnsTheModuleAndUnknownIdNamesTheKnownOnes(): void
    {
        $registry = $this->registry([
            'alpha' => fn (): FeatureModuleInterface => $this->module('alpha', true),
        ]);

        $this->assertSame('alpha', $registry->get('alpha')->id());

        $this->expectException(ModuleNotFoundException::class);
        $this->expectExceptionMessage('Unknown feature module "omega". Known modules: alpha');
        $registry->get('omega');
    }

    public function testConfiguredAndAbsentPartitionAllModules(): void
    {
        $registry = $this->registry([
            'on' => fn (): FeatureModuleInterface => $this->module('on', true),
            'off' => fn (): FeatureModuleInterface => $this->module('off', false),
        ]);

        $this->assertSame(['off', 'on'], array_keys($registry->all()));
        $this->assertSame(['on'], array_keys($registry->configured()));
        $this->assertSame(['off'], array_keys($registry->absent()));
    }

    public function testForRoutePicksTheLongestMatchingPrefixAndNullOtherwise(): void
    {
        $registry = $this->registry([
            'broad' => fn (): FeatureModuleInterface => $this->module('broad', true, ['api_ai_*']),
            'narrow' => fn (): FeatureModuleInterface => $this->module('narrow', true, ['api_ai_higgsfield_*']),
            'unrelated' => fn (): FeatureModuleInterface => $this->module('unrelated', true, ['api_stripe_*']),
        ]);

        $this->assertSame('narrow', $registry->forRoute('api_ai_higgsfield_generate')?->id());
        $this->assertSame('broad', $registry->forRoute('api_ai_models')?->id());
        $this->assertNull($registry->forRoute('api_config_runtime'));
    }

    public function testExactRouteNamesDoNotMatchAsPrefixes(): void
    {
        $registry = $this->registry([
            'whatsapp' => fn (): FeatureModuleInterface => $this->module('whatsapp', true, ['api_webhooks_whatsapp']),
        ]);

        $this->assertSame('whatsapp', $registry->forRoute('api_webhooks_whatsapp')?->id());
        $this->assertNull($registry->forRoute('api_webhooks_whatsapp_verify'), 'the Meta handshake route must stay unowned');
    }

    public function testExactMatchBeatsPrefixMatch(): void
    {
        $registry = $this->registry([
            'prefixed' => fn (): FeatureModuleInterface => $this->module('prefixed', true, ['subscription_*']),
            'exact' => fn (): FeatureModuleInterface => $this->module('exact', true, ['subscription_checkout']),
        ]);

        $this->assertSame('exact', $registry->forRoute('subscription_checkout')?->id());
        $this->assertSame('prefixed', $registry->forRoute('subscription_portal')?->id());
    }

    public function testEmptyOrBareWildcardNeverMatches(): void
    {
        $registry = $this->registry([
            'greedy' => fn (): FeatureModuleInterface => $this->module('greedy', true, ['', '*']),
        ]);

        $this->assertNull($registry->forRoute('anything'));
    }

    /**
     * @param array<string, callable(): FeatureModuleInterface> $factories
     */
    private function registry(array $factories): ModuleRegistry
    {
        return new ModuleRegistry(new ServiceLocator($factories));
    }

    /**
     * @param list<string> $routeNames
     */
    private function module(string $id, bool $configured, array $routeNames = []): FeatureModuleInterface
    {
        return new class($id, $configured, $routeNames) implements FeatureModuleInterface {
            /**
             * @param list<string> $routeNames
             */
            public function __construct(
                private readonly string $id,
                private readonly bool $configured,
                private readonly array $routeNames,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function labelKey(): string
            {
                return 'modules.'.$this->id.'.label';
            }

            public function configuredBy(): ConfiguredBy
            {
                return new ConfiguredBy();
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function status(): ModuleStatus
            {
                return new ModuleStatus($this->configured, $this->configured, 'stub');
            }

            public function capabilityIds(): array
            {
                return [];
            }

            public function routeNames(): array
            {
                return $this->routeNames;
            }

            public function serviceIds(): array
            {
                return [];
            }

            public function docsAnchor(): string
            {
                return 'modules/'.$this->id;
            }

            public function mobileClass(): MobileClass
            {
                return MobileClass::BackendOnly;
            }
        };
    }
}
