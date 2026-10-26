<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\AI\Provider\TestProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keep the mock provider out of production unless this process is a benchmark.
 *
 * Dev and test always keep it. Any other environment drops it, unless
 * SYNAPLAN_PERF_TEST_PROVIDER is truthy at container compile time. The runtime
 * benchmark sets that while it warms var/cache/prod and deletes that cache
 * afterwards, so a normal prod container never contains the mock.
 */
final class PerfTestProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TestProvider::class)) {
            return;
        }
        $environment = (string) $container->getParameter('kernel.environment');
        if ('dev' === $environment || 'test' === $environment || self::benchmarkEnabled()) {
            return;
        }
        $container->removeDefinition(TestProvider::class);
    }

    public static function benchmarkEnabled(): bool
    {
        $raw = $_SERVER['SYNAPLAN_PERF_TEST_PROVIDER']
            ?? $_ENV['SYNAPLAN_PERF_TEST_PROVIDER']
            ?? getenv('SYNAPLAN_PERF_TEST_PROVIDER');
        if (!is_string($raw) && !is_int($raw)) {
            return false;
        }

        return false !== filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
