<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReapEphemeralFilesCommand;
use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use App\Service\File\HeicConverter;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The reaper is the safety net for incognito sessions that could not clean
 * up their ephemeral files themselves (tab crash, network loss): it must
 * remove both the on-disk bytes and the DB row, and one broken entry must
 * not abort the sweep.
 */
final class ReapEphemeralFilesCommandTest extends TestCase
{
    private string $uploadDir;
    private FileRepository&MockObject $fileRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/ephemeral-reaper-test-'.uniqid();
        mkdir($this->uploadDir, 0777, true);

        $this->fileRepository = $this->createMock(FileRepository::class);

        // FileStorageService is final readonly — use the real service over a
        // throwaway temp dir instead of a mock.
        $storage = new FileStorageService(
            $this->uploadDir,
            new NullLogger(),
            new UserUploadPathBuilder(),
            new ThumbnailService($this->uploadDir, new NullLogger()),
            new HeicConverter(new NullLogger()),
        );

        $command = new ReapEphemeralFilesCommand(
            $this->fileRepository,
            $storage,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:files:reap-ephemeral'));
    }

    protected function tearDown(): void
    {
        // Best-effort temp dir cleanup (files are removed by the tests).
        foreach (glob($this->uploadDir.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->uploadDir);
    }

    public function testReapsExpiredEphemeralFileFromDiskAndDatabase(): void
    {
        $relativePath = 'incognito-upload.txt';
        file_put_contents($this->uploadDir.'/'.$relativePath, 'ephemeral content');

        $file = new File();
        $file->setUserId(7);
        $file->setFilePath($relativePath);
        $file->setEphemeral(true);

        $this->fileRepository->method('findExpiredEphemeral')->willReturn([$file]);
        $this->fileRepository->expects($this->once())->method('delete')->with($file);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($this->uploadDir.'/'.$relativePath);
        $this->assertStringContainsString('Deleted 1 expired ephemeral file', $this->commandTester->getDisplay());
    }

    public function testNoExpiredFilesIsANoOp(): void
    {
        $this->fileRepository->method('findExpiredEphemeral')->willReturn([]);
        $this->fileRepository->expects($this->never())->method('delete');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No expired ephemeral files', $this->commandTester->getDisplay());
    }

    public function testOneFailingRowDoesNotAbortTheSweep(): void
    {
        $good = new File();
        $good->setUserId(7);
        $good->setFilePath('good.txt');
        file_put_contents($this->uploadDir.'/good.txt', 'x');

        $broken = new File();
        $broken->setUserId(7);
        $broken->setFilePath('missing-but-db-delete-fails.txt');

        $this->fileRepository->method('findExpiredEphemeral')->willReturn([$broken, $good]);
        $this->fileRepository->method('delete')->willReturnCallback(function (File $file) use ($broken): void {
            if ($file === $broken) {
                throw new \RuntimeException('DB gone away');
            }
        });

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($this->uploadDir.'/good.txt');
        $this->assertStringContainsString('Deleted 1 expired ephemeral file', $this->commandTester->getDisplay());
    }

    public function testTtlOptionIsForwardedAsCutoff(): void
    {
        $capturedCutoff = null;
        $this->fileRepository
            ->method('findExpiredEphemeral')
            ->willReturnCallback(function (int $cutoff) use (&$capturedCutoff): array {
                $capturedCutoff = $cutoff;

                return [];
            });

        $before = time() - 2 * 3600;
        $this->commandTester->execute(['--ttl-hours' => '2']);
        $after = time() - 2 * 3600;

        $this->assertNotNull($capturedCutoff);
        $this->assertGreaterThanOrEqual($before, $capturedCutoff);
        $this->assertLessThanOrEqual($after, $capturedCutoff);
    }
}
