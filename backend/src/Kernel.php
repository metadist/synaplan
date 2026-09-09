<?php

namespace App;

use App\DependencyInjection\Compiler\EnableTestSavepointsPass;
use App\Plug\DependencyInjection\PlugDeclarationCheckPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** @var list<array{dir: string, namespace: string}>|null */
    private ?array $discoveredPlugins = null;

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Absolute config dir (not '../config') so a test kernel subclass living
        // in another directory still imports backend/config — __DIR__ here is
        // always backend/src regardless of the runtime subclass.
        $configDir = \dirname(__DIR__).'/config';
        $container->import($configDir.'/{packages}/*.yaml');
        $container->import($configDir.'/{packages}/'.$this->environment.'/*.yaml');

        if (is_file($configDir.'/services.yaml')) {
            $container->import($configDir.'/services.yaml');
            $container->import($configDir.'/{services}_'.$this->environment.'.yaml');
        } elseif (is_file($path = $configDir.'/services.php')) {
            (require $path)($container->withPath($path), $this);
        }

        if ('test' === $this->environment
            && class_exists(\DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class)
        ) {
            $container->extension('dama_doctrine_test', [
                'enable_static_connection' => true,
                'enable_static_meta_data_cache' => true,
                'enable_static_query_cache' => true,
            ]);
        }

        $this->loadPluginServices($container);

        // Keys a plugin declared in provides.plugs, so PlugKeyStore lets a
        // plugin adapter store and read its secret. Always set (default []) so
        // services.yaml can bind %plug.plugin_keys% unconditionally.
        $container->parameters()->set('plug.plugin_keys', $this->collectPluginPlugKeys());
    }

    /**
     * @return list<string>
     */
    private function collectPluginPlugKeys(): array
    {
        $keys = [];
        foreach ($this->getPlugins() as $plugin) {
            $manifestPath = $plugin['dir'].'/manifest.json';
            $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
            $data = false === $raw ? null : json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $plugs = $data['provides']['plugs'] ?? null;
            if (!is_array($plugs)) {
                continue;
            }
            foreach ($plugs as $plug) {
                if (is_array($plug) && is_string($plug['key'] ?? null) && '' !== $plug['key']) {
                    $keys[strtolower($plug['key'])] = true;
                }
            }
        }

        return array_keys($keys);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Nested-transaction savepoints are required by dama/doctrine-test-bundle
        // on MariaDB/MySQL. We register the compiler pass conditionally because
        // the doctrine-bundle YAML key `use_savepoints` is only available with
        // DBAL 4.x, and we currently ship against DBAL 3.x. See the pass's
        // class docblock for the full rationale.
        if ('test' === $this->environment) {
            $container->addCompilerPass(new EnableTestSavepointsPass());
        }

        // A plugin class that implements a plug interface is tagged app.plug.*
        // by autoconfiguration; this pass refuses one the manifest did not
        // declare in provides.plugs, so contributing an adapter is explicit.
        $container->addCompilerPass(new PlugDeclarationCheckPass($this->getPlugins()));
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = \dirname(__DIR__).'/config';
        $routes->import($configDir.'/{routes}/'.$this->environment.'/*.yaml');
        $routes->import($configDir.'/{routes}/*.yaml');

        if (is_file($configDir.'/routes.yaml')) {
            $routes->import($configDir.'/routes.yaml');
        } elseif (is_file($path = $configDir.'/routes.php')) {
            (require $path)($routes->withPath($path), $this);
        }

        $this->loadPluginRoutes($routes);
    }

    private function loadPluginServices(ContainerConfigurator $container): void
    {
        $plugins = $this->getPlugins();
        if ([] === $plugins) {
            return;
        }

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure();

        foreach ($plugins as $plugin) {
            $services
                ->load($plugin['namespace'].'\\', $plugin['dir'].'/backend/')
                ->exclude($plugin['dir'].'/backend/{Entity,migrations,tests}');
        }
    }

    private function loadPluginRoutes(RoutingConfigurator $routes): void
    {
        foreach ($this->getPlugins() as $plugin) {
            $controllerDir = $plugin['dir'].'/backend/Controller';
            if (is_dir($controllerDir)) {
                $routes->import($controllerDir, 'attribute');
            }
        }
    }

    /**
     * Discovers plugins and registers their autoload paths (once).
     *
     * @return list<array{dir: string, namespace: string}>
     */
    private function getPlugins(): array
    {
        if (null !== $this->discoveredPlugins) {
            return $this->discoveredPlugins;
        }

        $pluginsDir = $this->resolvePluginsDir();
        if (null === $pluginsDir) {
            return $this->discoveredPlugins = [];
        }

        $manifests = glob($pluginsDir.'/*/manifest.json');
        if (!$manifests) {
            return $this->discoveredPlugins = [];
        }

        $plugins = [];
        foreach ($manifests as $manifestPath) {
            $pluginDir = \dirname($manifestPath);
            if (!is_dir($pluginDir.'/backend')) {
                continue;
            }

            $data = json_decode(file_get_contents($manifestPath), true);
            if (!$data) {
                continue;
            }

            $namespace = $data['namespace'] ?? null;
            if (null === $namespace) {
                $id = $data['id'] ?? basename($pluginDir);
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                    continue;
                }
                $namespace = 'Plugin\\'.ucfirst($id);
            }

            $plugins[] = ['dir' => $pluginDir, 'namespace' => $namespace];
        }

        $this->discoveredPlugins = $plugins;

        if ([] !== $plugins) {
            $loader = require $this->getProjectDir().'/vendor/autoload.php';
            foreach ($plugins as $plugin) {
                $loader->addPsr4($plugin['namespace'].'\\', $plugin['dir'].'/backend/');
            }
        }

        return $plugins;
    }

    private function resolvePluginsDir(): ?string
    {
        // Test-only override so a suite can boot against a fixture plugin
        // directory without touching the real /plugins mount.
        $override = getenv('PLUGINS_DIR');
        if (is_string($override) && '' !== $override && is_dir($override)) {
            return $override;
        }

        if (is_dir('/plugins')) {
            return '/plugins';
        }

        $local = \dirname(__DIR__).'/plugins';
        if (is_dir($local)) {
            return $local;
        }

        return null;
    }
}
