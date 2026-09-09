<?php

use Symfony\Component\Dotenv\Dotenv;

$autoloader = require dirname(__DIR__).'/vendor/autoload.php';

// Register PSR-4 for discovered plugins so plugin classes (e.g. the serper_search
// reference adapter) autoload in the test process just as the kernel does at
// runtime. Mirrors App\Kernel plugin discovery; dir differs dev vs CI.
(static function (Composer\Autoload\ClassLoader $loader): void {
    $candidates = array_filter([
        getenv('PLUGINS_DIR') ?: null,
        '/plugins',
        dirname(__DIR__, 2).'/plugins',
    ]);
    foreach ($candidates as $pluginsDir) {
        if (!is_dir($pluginsDir)) {
            continue;
        }
        foreach (glob($pluginsDir.'/*/manifest.json') ?: [] as $manifestPath) {
            $dir = dirname($manifestPath);
            if (!is_dir($dir.'/backend')) {
                continue;
            }
            $data = json_decode((string) file_get_contents($manifestPath), true);
            $namespace = is_array($data) && is_string($data['namespace'] ?? null)
                ? $data['namespace']
                : 'Plugin\\'.ucfirst(is_array($data) ? (string) ($data['id'] ?? basename($dir)) : basename($dir));
            $loader->addPsr4(rtrim($namespace, '\\').'\\', $dir.'/backend/');
        }

        break;
    }
})($autoloader);

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Load .env.test if running tests (PHPUnit)
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'test') {
    if (file_exists(dirname(__DIR__).'/.env.test')) {
        (new Dotenv())->load(dirname(__DIR__).'/.env.test');
    }
}
