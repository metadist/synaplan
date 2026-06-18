<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Media\GeneratedFileRegistrar;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GeneratedFileRegistrarTest extends TestCase
{
    private string $uploadDir;
    private FileRepository&MockObject $files;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/registrar_test_'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0777, true);
        $this->files = $this->createMock(FileRepository::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    private function registrar(): GeneratedFileRegistrar
    {
        return new GeneratedFileRegistrar($this->files, new NullLogger(), $this->uploadDir);
    }

    public function testRegistersFileOnDiskWithMetadata(): void
    {
        file_put_contents($this->uploadDir.'/voice_123.mp3', 'mp3-bytes');

        $this->files->expects(self::once())->method('save')->with(self::isInstanceOf(File::class));

        $file = $this->registrar()->register(42, 'voice_123.mp3', 'audio');

        self::assertInstanceOf(File::class, $file);
        self::assertSame(42, $file->getUserId());
        self::assertSame('voice_123.mp3', $file->getFilePath());
        self::assertSame('voice_123.mp3', $file->getFileName());
        self::assertSame('audio', $file->getFileType());
        self::assertSame(strlen('mp3-bytes'), $file->getFileSize());
        self::assertSame('audio/mpeg', $file->getFileMime());
        self::assertSame('generated', $file->getStatus());
    }

    public function testFallsBackToExtensionWhenTypeEmpty(): void
    {
        file_put_contents($this->uploadDir.'/pic.png', 'png');

        $file = $this->registrar()->register(1, 'pic.png', '');

        self::assertInstanceOf(File::class, $file);
        self::assertSame('png', $file->getFileType());
        self::assertSame('image/png', $file->getFileMime());
    }

    public function testMissingFileOnDiskStillRegistersWithZeroSize(): void
    {
        // Mirrors the StreamController behaviour: a handler may report a path
        // the registrar cannot stat — register anyway so history stays whole.
        $file = $this->registrar()->register(1, 'gone.mp4', 'video');

        self::assertInstanceOf(File::class, $file);
        self::assertSame(0, $file->getFileSize());
        self::assertSame('video/mp4', $file->getFileMime());
    }

    public function testNullOrEmptyPathReturnsNull(): void
    {
        $this->files->expects(self::never())->method('save');

        self::assertNull($this->registrar()->register(1, null, 'audio'));
        self::assertNull($this->registrar()->register(1, '', 'audio'));
    }

    public function testPersistenceFailureReturnsNullInsteadOfThrowing(): void
    {
        $this->files->method('save')->willThrowException(new \RuntimeException('db down'));

        self::assertNull($this->registrar()->register(1, 'voice.mp3', 'audio'));
    }
}
