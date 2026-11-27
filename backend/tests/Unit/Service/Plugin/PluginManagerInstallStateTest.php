<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Service\File\FileStorageService;
use App\Service\Plugin\PluginManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PluginManagerInstallStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/plugin-install-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/central/synaform/backend', 0777, true);
        mkdir($this->root.'/central/synaform/frontend', 0777, true);
        mkdir($this->root.'/user/plugins', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testACompleteLinkSetCountsAsInstalled(): void
    {
        $this->linkInstall();

        self::assertTrue($this->manager()->isInstalled(7, 'synaform'));
    }

    public function testAnEmptyDirectoryDoesNotCountAsInstalled(): void
    {
        mkdir($this->root.'/user/plugins/synaform');

        self::assertFalse($this->manager()->isInstalled(7, 'synaform'));
    }

    public function testABackendLinkToTheWrongTreeDoesNotCountAsInstalled(): void
    {
        $this->linkInstall();
        $elsewhere = $this->root.'/elsewhere';
        mkdir($elsewhere);
        unlink($this->root.'/user/plugins/synaform/backend');
        symlink($elsewhere, $this->root.'/user/plugins/synaform/backend');

        self::assertFalse($this->manager()->isInstalled(7, 'synaform'));
    }

    public function testABrokenUpLinkDoesNotCountAsInstalled(): void
    {
        $this->linkInstall();
        unlink($this->root.'/user/plugins/synaform/up');
        symlink($this->root.'/missing', $this->root.'/user/plugins/synaform/up');

        self::assertFalse($this->manager()->isInstalled(7, 'synaform'));
    }

    private function linkInstall(): void
    {
        $target = $this->root.'/user/plugins/synaform';
        $central = $this->root.'/central/synaform';
        mkdir($target);
        symlink($central.'/backend', $target.'/backend');
        symlink($central.'/frontend', $target.'/frontend');
        symlink('../../../', $target.'/up');
    }

    private function manager(): PluginManager
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects($this->once())->method('getUserBaseAbsolutePath')->with(7)->willReturn($this->root.'/user');

        return new PluginManager(
            $storage,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->root.'/central',
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_link($child) || is_file($child)) {
                unlink($child);
                continue;
            }
            $this->removeTree($child);
        }
        rmdir($path);
    }
}
