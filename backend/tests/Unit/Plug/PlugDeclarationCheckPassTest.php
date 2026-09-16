<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\DependencyInjection\PlugDeclarationCheckPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class PlugDeclarationCheckPassTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir().'/plug-decl-'.uniqid();
        mkdir($this->pluginDir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->pluginDir.'/manifest.json');
        @rmdir($this->pluginDir);
    }

    public function testDeclaredAdapterPasses(): void
    {
        $this->writeManifest([
            'id' => 'demo_search',
            'namespace' => 'Plugin\\DemoSearch',
            'provides' => ['plugs' => [
                ['port' => 'web_search', 'class' => 'Plugin\\DemoSearch\\Adapter', 'key' => 'demo'],
            ]],
        ]);

        $container = $this->containerWithTaggedAdapter('app.plug.web_search', 'Plugin\\DemoSearch\\Adapter');
        $this->pass()->process($container);

        $this->addToAssertionCount(1); // no exception
    }

    public function testUndeclaredAdapterFailsCompilation(): void
    {
        $this->writeManifest([
            'id' => 'demo_search',
            'namespace' => 'Plugin\\DemoSearch',
        ]);

        $container = $this->containerWithTaggedAdapter('app.plug.web_search', 'Plugin\\DemoSearch\\Adapter');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Plugin "demo_search" registers Plugin\\DemoSearch\\Adapter for port web_search but does not declare it in manifest.json provides.plugs');
        $this->pass()->process($container);
    }

    public function testWrongPortDeclarationFails(): void
    {
        $this->writeManifest([
            'id' => 'demo_search',
            'namespace' => 'Plugin\\DemoSearch',
            'provides' => ['plugs' => [
                ['port' => 'rerank', 'class' => 'Plugin\\DemoSearch\\Adapter', 'key' => 'demo'],
            ]],
        ]);

        $container = $this->containerWithTaggedAdapter('app.plug.web_search', 'Plugin\\DemoSearch\\Adapter');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('for port web_search');
        $this->pass()->process($container);
    }

    public function testNestedPluginNamespacesAttributeToTheLongestPrefix(): void
    {
        // Two plugins whose namespaces nest: Plugin\Foo and Plugin\Foo\Bar.
        $outer = sys_get_temp_dir().'/plug-outer-'.uniqid();
        $inner = sys_get_temp_dir().'/plug-inner-'.uniqid();
        mkdir($outer, 0777, true);
        mkdir($inner, 0777, true);
        file_put_contents($outer.'/manifest.json', json_encode(['id' => 'foo', 'namespace' => 'Plugin\\Foo'], JSON_THROW_ON_ERROR));
        file_put_contents($inner.'/manifest.json', json_encode(['id' => 'foo_bar', 'namespace' => 'Plugin\\Foo\\Bar'], JSON_THROW_ON_ERROR));

        try {
            $pass = new PlugDeclarationCheckPass([
                ['dir' => $outer, 'namespace' => 'Plugin\\Foo'],
                ['dir' => $inner, 'namespace' => 'Plugin\\Foo\\Bar'],
            ]);
            $container = $this->containerWithTaggedAdapter('app.plug.web_search', 'Plugin\\Foo\\Bar\\Adapter');

            $this->expectException(\LogicException::class);
            // The inner plugin (foo_bar) owns the class, not the ancestor foo.
            $this->expectExceptionMessage('Plugin "foo_bar" registers Plugin\\Foo\\Bar\\Adapter');
            $pass->process($container);
        } finally {
            @unlink($outer.'/manifest.json');
            @unlink($inner.'/manifest.json');
            @rmdir($outer);
            @rmdir($inner);
        }
    }

    public function testCoreAdapterIsExempt(): void
    {
        $this->writeManifest(['id' => 'demo_search', 'namespace' => 'Plugin\\DemoSearch']);

        $container = $this->containerWithTaggedAdapter('app.plug.web_search', 'App\\Plug\\WebSearch\\Adapter\\TavilyAdapter');
        $this->pass()->process($container);

        $this->addToAssertionCount(1); // core class under App\ is never checked
    }

    private function pass(): PlugDeclarationCheckPass
    {
        return new PlugDeclarationCheckPass([['dir' => $this->pluginDir, 'namespace' => 'Plugin\\DemoSearch']]);
    }

    private function containerWithTaggedAdapter(string $tag, string $class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $definition = new Definition($class);
        $definition->addTag($tag);
        $container->setDefinition($class, $definition);

        return $container;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeManifest(array $data): void
    {
        file_put_contents($this->pluginDir.'/manifest.json', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
