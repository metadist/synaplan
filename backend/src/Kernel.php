<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import('../config/{packages}/*.yaml');
        $container->import('../config/{packages}/'.$this->environment.'/*.yaml');

        if (is_file(\dirname(__DIR__).'/config/services.yaml')) {
            $container->import('../config/services.yaml');
            $container->import('../config/{services}_'.$this->environment.'.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/services.php')) {
            (require $path)($container->withPath($path), $this);
        }

        $this->loadPluginServices($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('../config/{routes}/'.$this->environment.'/*.yaml');
        $routes->import('../config/{routes}/*.yaml');

        if (is_file(\dirname(__DIR__).'/config/routes.yaml')) {
            $routes->import('../config/routes.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/routes.php')) {
            (require $path)($routes->withPath($path), $this);
        }

        $this->loadPluginRoutes($routes);
    }

    private function loadPluginServices(ContainerConfigurator $container): void
    {
        $pluginsDir = $this->resolvePluginsDir();
        if (null === $pluginsDir) {
            return;
        }

        $plugins = $this->discoverPlugins($pluginsDir);
        if ([] === $plugins) {
            return;
        }

        $this->registerPluginAutoloadPaths($plugins);

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure()
            ->bind('$uploadDir', '%kernel.project_dir%/var/uploads');

        foreach ($plugins as $plugin) {
            $services
                ->load($plugin['namespace'].'\\', $plugin['dir'].'/backend/')
                ->exclude($plugin['dir'].'/backend/{Entity,migrations,tests}');
        }
    }

    /**
     * @return list<array{dir: string, namespace: string}>
     */
    private function discoverPlugins(string $pluginsDir): array
    {
        $manifests = glob($pluginsDir.'/*/manifest.json');
        if (!$manifests) {
            return [];
        }

        $plugins = [];
        foreach ($manifests as $manifestPath) {
            $pluginDir = \dirname($manifestPath);
            $backendDir = $pluginDir.'/backend';
            if (!is_dir($backendDir)) {
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

        return $plugins;
    }

    private function loadPluginRoutes(RoutingConfigurator $routes): void
    {
        $pluginsDir = $this->resolvePluginsDir();
        if (null === $pluginsDir) {
            return;
        }

        foreach ($this->discoverPlugins($pluginsDir) as $plugin) {
            $controllerDir = $plugin['dir'].'/backend/Controller';
            if (is_dir($controllerDir)) {
                $routes->import($controllerDir, 'attribute');
            }
        }
    }

    /**
     * @param list<array{dir: string, namespace: string}> $plugins
     */
    private function registerPluginAutoloadPaths(array $plugins): void
    {
        $loader = require $this->getProjectDir().'/vendor/autoload.php';
        foreach ($plugins as $plugin) {
            $loader->addPsr4($plugin['namespace'].'\\', $plugin['dir'].'/backend/');
        }
    }

    private function resolvePluginsDir(): ?string
    {
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
