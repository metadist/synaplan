<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Module\Contract\FeatureModuleInterface;
use App\Module\DependencyInjection\FeatureModuleTagPass;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;

/**
 * The shape every descriptor must have (master plan §4.1), checked against
 * the real classes so a lazy `serviceIds()` list or a typo in a route name
 * cannot slip through.
 */
final class ModuleDescriptorContractTest extends TestCase
{
    use BuildsAllModules;

    /** Master plan §0 row 3 — the v1 module set. */
    private const PLAN_MODULE_IDS = [
        'tika',
        'docling',
        'office_convert',
        'searxng',
        'piper_tts',
        'local_ai',
        'higgsfield',
        'google_ai',
        'thehive',
        'stripe_billing',
        'mobile_iap',
        'whatsapp',
    ];

    public function testTheDescriptorSetIsExactlyThePlan(): void
    {
        $ids = array_keys($this->allModules());
        sort($ids);
        $plan = self::PLAN_MODULE_IDS;
        sort($plan);

        $this->assertSame($plan, $ids);
    }

    public function testIdConstantAndIdMethodAgreeAndAreSnakeCase(): void
    {
        foreach ($this->allModules() as $module) {
            $constant = (new \ReflectionClass($module))->getConstant('ID');
            $this->assertSame($constant, $module->id(), $module::class.' — public const ID must equal id()');
            $this->assertMatchesRegularExpression(FeatureModuleTagPass::ID_PATTERN, $module->id());
        }
    }

    public function testLabelKeyAndDocsAnchorFollowTheConvention(): void
    {
        foreach ($this->allModules() as $id => $module) {
            $this->assertSame('modules.'.$id.'.label', $module->labelKey());
            $this->assertMatchesRegularExpression('#^modules/[a-z0-9-]+$#', $module->docsAnchor(), $id);
        }
    }

    public function testEveryModuleIsConfiguredByAtLeastOneEnvKey(): void
    {
        foreach ($this->allModules() as $id => $module) {
            $by = $module->configuredBy();
            $this->assertNotEmpty($by->envKeys, "{$id} must be configurable by env (master plan §0 row 4)");
            $this->assertSame(['env', 'bconfig', 'providers', 'plugs'], array_keys($by->toArray()));
            foreach ($by->envKeys as $key) {
                $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $key, "{$id} env key {$key}");
            }
        }
    }

    public function testNoEnvKeyIsOwnedByTwoModules(): void
    {
        $owners = [];
        foreach ($this->allModules() as $id => $module) {
            foreach ($module->configuredBy()->envKeys as $key) {
                $owners[$key][] = $id;
            }
        }

        $shared = array_filter($owners, static fn (array $ids): bool => count($ids) > 1);
        $this->assertSame([], $shared, 'env keys with more than one owning module');
    }

    public function testServiceIdsAreRealClassesAndOwnedOnce(): void
    {
        $owners = [];
        foreach ($this->allModules() as $id => $module) {
            $services = $module->serviceIds();
            $this->assertNotEmpty($services, "{$id} must own at least one service");
            $this->assertSame(array_values(array_unique($services)), $services, "{$id} lists a service twice");
            foreach ($services as $class) {
                $this->assertTrue(class_exists($class) || interface_exists($class), "{$id} declares unknown service {$class}");
                $owners[$class][] = $id;
            }
        }

        $shared = array_filter($owners, static fn (array $ids): bool => count($ids) > 1);
        $this->assertSame([], $shared, 'services owned by more than one module');
    }

    public function testRouteNamesAreWellFormedAndOwnedOnce(): void
    {
        $owners = [];
        foreach ($this->allModules() as $id => $module) {
            foreach ($module->routeNames() as $route) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\*?$/', $route, "{$id} route pattern {$route}");
                $owners[$route][] = $id;
            }
        }

        $shared = array_filter($owners, static fn (array $ids): bool => count($ids) > 1);
        $this->assertSame([], $shared, 'route patterns owned by more than one module');
    }

    public function testCapabilityIdsAreOwnedOnce(): void
    {
        $owners = [];
        foreach ($this->allModules() as $id => $module) {
            foreach ($module->capabilityIds() as $capability) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $capability, "{$id} capability {$capability}");
                $owners[$capability][] = $id;
            }
        }

        $shared = array_filter($owners, static fn (array $ids): bool => count($ids) > 1);
        $this->assertSame([], $shared, 'capabilities owned by more than one module');
    }

    public function testUnconfiguredDescriptorsReportAbsentWithoutThrowing(): void
    {
        foreach ($this->allModules() as $id => $module) {
            $this->assertInstanceOf(FeatureModuleInterface::class, $module);
            $this->assertFalse($module->isConfigured(), "{$id} built with empty config must not be configured");
            $status = $module->status();
            $this->assertFalse($status->configured, $id);
            $this->assertFalse($status->healthy, $id);
            $this->assertSame('absent', $status->state(), $id);
            $this->assertNotSame('', $status->message, $id);
        }
    }
}
