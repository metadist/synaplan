<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plug;

use App\Tests\Support\FixturePluginsKernel;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * A plugin class the compiled container does not include itself (a value
 * object built with `new`, a static factory's helper) must still load when the
 * kernel boots from a warm cache, which is every production request.
 */
final class PluginAutoloadOnWarmBootTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/plugins/declared';
    private const PREFIX = 'Plugin\\FixtureSearch\\';

    public function testWarmBootRegistersThePluginNamespace(): void
    {
        $uid = 'warm-'.uniqid();
        $cold = new FixturePluginsKernel(self::FIXTURES, $uid);
        $cold->boot();
        $cold->shutdown();

        $loader = $this->composerLoader();
        $loader->setPsr4(self::PREFIX, []);

        $warm = new FixturePluginsKernel(self::FIXTURES, $uid);
        try {
            $warm->boot();

            $paths = $loader->getPrefixesPsr4()[self::PREFIX] ?? [];
            self::assertNotSame([], $paths, 'the warm boot registers the plugin namespace');
            self::assertStringEndsWith('fixture_search/backend/', str_replace('\\', '/', (string) end($paths)));
        } finally {
            $warm->shutdown();
        }
    }

    private function composerLoader(): ClassLoader
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (\array_key_exists('App\\', $loader->getPrefixesPsr4())) {
                return $loader;
            }
        }

        self::fail('The composer class loader for App\\ is not registered.');
    }
}
