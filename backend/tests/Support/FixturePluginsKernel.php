<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;

/**
 * Boots the real application kernel against a fixture plugins directory, with a
 * unique cache/build dir so the container is compiled fresh for that plugin set
 * (and the plug-declaration compiler pass actually runs). Test-only.
 */
final class FixturePluginsKernel extends Kernel
{
    public function __construct(
        private readonly string $pluginsDir,
        private readonly string $uid,
    ) {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/syn_fixture_plugins/'.$this->uid.'/cache';
    }

    public function getBuildDir(): string
    {
        return sys_get_temp_dir().'/syn_fixture_plugins/'.$this->uid.'/build';
    }

    public function boot(): void
    {
        // resolvePluginsDir() reads PLUGINS_DIR; set it before the parent boots
        // and discovers plugins / registers the compiler pass.
        putenv('PLUGINS_DIR='.$this->pluginsDir);
        $_ENV['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;

        parent::boot();
    }

    public function shutdown(): void
    {
        parent::shutdown();

        putenv('PLUGINS_DIR');
        unset($_ENV['PLUGINS_DIR'], $_SERVER['PLUGINS_DIR']);
    }
}
