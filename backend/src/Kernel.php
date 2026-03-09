<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
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
        $plugins = $this->getPlugins();
        if ([] === $plugins) {
            return;
        }

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
