<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\SystemConfig;

use App\Service\Admin\SystemConfig\EnvSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EnvSettingsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/env-settings-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir);
        file_put_contents($this->projectDir.'/.env', "APP_ENV=prod\nOLLAMA_BASE_URL=http://ollama:11434\n");
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->projectDir.'/var/env-backups/{,.}*', \GLOB_BRACE) ?: [], ...glob($this->projectDir.'/{,.}*', \GLOB_BRACE) ?: []] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($this->projectDir.'/var/env-backups');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testAWriteReplacesTheLineOrAppendsOneAndNeedsARestart(): void
    {
        $env = $this->settings();

        self::assertSame(['success' => true, 'requiresRestart' => true], $env->write('OLLAMA_BASE_URL', 'http://gpu:11434'));
        self::assertSame(['success' => true, 'requiresRestart' => true], $env->write('APP_SENDER_NAME', 'Acme Support'));
        self::assertSame("APP_ENV=prod\nOLLAMA_BASE_URL=http://gpu:11434\nAPP_SENDER_NAME=\"Acme Support\"\n", $this->envFile());
        self::assertNotSame([], $env->backups(), 'Every write backs the file up first.');
    }

    public function testRemovingALineNeedsARestartAndAnAbsentKeyIsAlreadyClear(): void
    {
        $env = $this->settings();

        self::assertSame(['success' => true, 'requiresRestart' => false], $env->remove('MAILER_DSN'));
        self::assertSame([], $env->backups(), 'Nothing changed, nothing backed up.');
        self::assertSame(['success' => true, 'requiresRestart' => true], $env->remove('OLLAMA_BASE_URL'));
        self::assertSame("APP_ENV=prod\n", $this->envFile());
    }

    public function testWithoutAnEnvFileNothingIsWritten(): void
    {
        unlink($this->projectDir.'/.env');

        self::assertSame(['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'], $this->settings()->write('APP_ENV', 'dev'));
        self::assertSame(['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'], $this->settings()->remove('APP_ENV'));
    }

    public function testTheFiveNewestBackupsStayAndABackupRestoresTheFile(): void
    {
        mkdir($this->projectDir.'/var/env-backups', 0o755, true);
        foreach (range(1, 6) as $day) {
            file_put_contents(sprintf('%s/var/env-backups/.env.backup.2020010%d_120000', $this->projectDir, $day), "APP_ENV=day{$day}\n");
        }
        $env = $this->settings();

        $env->write('APP_ENV', 'dev');
        $kept = array_column($env->backups(), 'id');

        self::assertCount(5, $kept);
        self::assertSame(['20200106_120000', '20200105_120000', '20200104_120000', '20200103_120000'], \array_slice($kept, 1));
        self::assertSame(['success' => true, 'message' => 'Backup restored successfully'], $env->restore('20200104_120000'));
        self::assertSame("APP_ENV=day4\n", $this->envFile());
        self::assertSame(['success' => false, 'message' => 'Backup not found'], $env->restore('20190101_000000'));
    }

    public function testTheRunningValueComesFromTheProcessEnvironment(): void
    {
        $_ENV['ENV_SETTINGS_TEST_KEY'] = 'from-env-array';
        putenv('ENV_SETTINGS_TEST_OTHER=from-getenv');

        try {
            self::assertSame(['from-env-array', 'from-getenv', null], [
                $this->settings()->value('ENV_SETTINGS_TEST_KEY'),
                $this->settings()->value('ENV_SETTINGS_TEST_OTHER'),
                $this->settings()->value('ENV_SETTINGS_TEST_ABSENT'),
            ]);
        } finally {
            unset($_ENV['ENV_SETTINGS_TEST_KEY']);
            putenv('ENV_SETTINGS_TEST_OTHER');
        }
    }

    /**
     * Pins a carried finding: the new line is a preg_replace() replacement,
     * so a "$1" in a value that replaces an existing line is dropped.
     */
    public function testADollarGroupInAValueIsLostWhenItReplacesALine(): void
    {
        $this->settings()->write('OLLAMA_BASE_URL', 'pa$1word');

        self::assertSame("APP_ENV=prod\nOLLAMA_BASE_URL=paword\n", $this->envFile());
    }

    private function settings(): EnvSettings
    {
        return new EnvSettings($this->projectDir, new NullLogger());
    }

    private function envFile(): string
    {
        return (string) file_get_contents($this->projectDir.'/.env');
    }
}
