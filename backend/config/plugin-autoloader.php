<?php

declare(strict_types=1);

/**
 * Plugin auto-discovery autoloader.
 *
 * Maps Plugin\{Name}\... to /plugins/{name}/backend/...
 * Handles the namespace-to-directory mismatch: PHP namespaces use PascalCase
 * (Plugin\SortX), but plugin directories use lowercase (plugins/sortx/backend/).
 */
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Plugin\\')) {
        return;
    }

    $parts = explode('\\', $class);
    array_shift($parts);
    $pluginName = strtolower(array_shift($parts));

    $pluginsDir = '/plugins';
    if (!is_dir($pluginsDir)) {
        $pluginsDir = dirname(__DIR__).'/plugins';
    }

    $file = $pluginsDir.'/'.$pluginName.'/backend/'.implode('/', $parts).'.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
