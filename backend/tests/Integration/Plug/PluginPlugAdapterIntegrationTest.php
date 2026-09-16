<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plug;

use App\Plug\WebSearch\WebSearchQuery;
use App\Plug\WebSearch\WebSearchRegistry;
use App\Tests\Support\FixturePluginsKernel;
use PHPUnit\Framework\TestCase;

/**
 * PL43: a plugin contributes a web-search adapter with zero edits to
 * backend/src, and an undeclared adapter is refused at boot.
 */
final class PluginPlugAdapterIntegrationTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/plugins';

    public function testDeclaredPluginAdapterIsPickedUpByTheRegistry(): void
    {
        $kernel = new FixturePluginsKernel(self::FIXTURES.'/declared', 'declared-'.uniqid());
        $kernel->boot();

        try {
            // framework.test exposes private services through the test container.
            $testContainer = $kernel->getContainer()->get('test.service_container');
            /** @var WebSearchRegistry $registry */
            $registry = $testContainer->get(WebSearchRegistry::class);

            $adapter = $registry->byKey('fixture_search');
            self::assertNotNull($adapter, 'the declared plugin adapter is registered');
            self::assertSame('fixture_search', $adapter->descriptor()->pluginId);

            $set = $adapter->search(new WebSearchQuery('anything'));
            self::assertSame('https://example.test/hit', $set->results[0]['url'] ?? null);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testUndeclaredPluginAdapterFailsBoot(): void
    {
        $kernel = new FixturePluginsKernel(self::FIXTURES.'/undeclared', 'undeclared-'.uniqid());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not declare it in manifest.json provides.plugs');

        try {
            $kernel->boot();
        } finally {
            $kernel->shutdown();
        }
    }
}
