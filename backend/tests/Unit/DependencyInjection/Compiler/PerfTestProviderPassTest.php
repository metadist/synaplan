<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection\Compiler;

use App\AI\Provider\TestProvider;
use App\DependencyInjection\Compiler\PerfTestProviderPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PerfTestProviderPassTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('SYNAPLAN_PERF_TEST_PROVIDER');
        unset($_SERVER['SYNAPLAN_PERF_TEST_PROVIDER'], $_ENV['SYNAPLAN_PERF_TEST_PROVIDER']);
        putenv('SYNAPLAN_PERF_TEST_PROVIDER');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SYNAPLAN_PERF_TEST_PROVIDER'], $_ENV['SYNAPLAN_PERF_TEST_PROVIDER']);
        if (false === $this->saved) {
            putenv('SYNAPLAN_PERF_TEST_PROVIDER');
        } else {
            putenv('SYNAPLAN_PERF_TEST_PROVIDER='.$this->saved);
        }
    }

    public function testDevKeepsTheProvider(): void
    {
        $container = $this->container('dev');

        (new PerfTestProviderPass())->process($container);

        self::assertTrue($container->hasDefinition(TestProvider::class));
    }

    public function testProdDropsTheProvider(): void
    {
        $container = $this->container('prod');

        (new PerfTestProviderPass())->process($container);

        self::assertFalse($container->hasDefinition(TestProvider::class));
    }

    public function testProdKeepsTheProviderWhenTheBenchmarkFlagIsSet(): void
    {
        $_SERVER['SYNAPLAN_PERF_TEST_PROVIDER'] = '1';
        $_ENV['SYNAPLAN_PERF_TEST_PROVIDER'] = '1';
        putenv('SYNAPLAN_PERF_TEST_PROVIDER=1');
        $container = $this->container('prod');

        (new PerfTestProviderPass())->process($container);

        self::assertTrue($container->hasDefinition(TestProvider::class));
    }

    private function container(string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);
        $container->register(TestProvider::class, TestProvider::class);

        return $container;
    }
}
