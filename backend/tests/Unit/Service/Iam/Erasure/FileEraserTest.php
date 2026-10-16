<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\File;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use App\Service\Iam\Erasure\FileEraser;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A file's revisions go with the file: both inside the transaction, before
 * the upload directories are tidied after the commit.
 */
final class FileEraserTest extends TestCase
{
    public function testRemovesTheRevisionsBeforeTheFilesTheyVersion(): void
    {
        $order = [];
        $file = new File();
        $revisions = $this->createMock(DocumentRevisionRepository::class);
        $revisions->expects(self::once())->method('deleteForUser')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'revisions';
            });
        $files = $this->createStub(FileRepository::class);
        $files->method('findBy')->willReturn([$file]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$order, $file): void {
            $order[] = $row === $file ? 'file' : 'other';
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new FileEraser($em, $files, $revisions, $this->createStub(FileStorageService::class), new NullLogger()))->erase($erasure);

        self::assertSame(['revisions', 'file'], $order);
        self::assertCount(1, $erasure->pendingCleanups());
    }
}
