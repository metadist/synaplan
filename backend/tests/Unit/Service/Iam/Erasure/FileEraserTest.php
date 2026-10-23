<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\File;
use App\Repository\CloudDeliveryRepository;
use App\Repository\CloudPickupFileRepository;
use App\Repository\CloudPickupRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use App\Service\Iam\Erasure\FileEraser;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Revisions and file rows go inside the transaction; the stored bytes are
 * deleted only after the commit, because a rollback cannot restore them.
 */
final class FileEraserTest extends TestCase
{
    public function testRemovesTheRowsInsideTheTransactionAndTheBytesAfterTheCommit(): void
    {
        $order = [];
        $file = (new File())->setFilePath('42/2026/10/report.pdf');
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::once())->method('deleteForUser')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'deliveries';
            });
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->expects(self::once())->method('deleteForUser')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'pickups';
            });
        $pickupFiles = $this->createMock(CloudPickupFileRepository::class);
        $pickupFiles->expects(self::once())->method('deleteForUser')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'pickup files';
            });
        $revisions = $this->createMock(DocumentRevisionRepository::class);
        $revisions->expects(self::once())->method('deleteForUser')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'revisions';
            });
        $files = $this->createStub(FileRepository::class);
        $files->method('findBy')->willReturn([$file]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'file row';
        });
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('deleteFile')->willReturnCallback(static function (string $path) use (&$order): bool {
            $order[] = 'bytes '.$path;

            return true;
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new FileEraser($em, $files, $revisions, $deliveries, $pickups, $pickupFiles, $storage, new NullLogger()))->erase($erasure);

        self::assertSame(['deliveries', 'pickups', 'pickup files', 'revisions', 'file row'], $order, 'No byte may be deleted before the commit.');
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
        self::assertSame(['deliveries', 'pickups', 'pickup files', 'revisions', 'file row', 'bytes 42/2026/10/report.pdf'], $order);
    }
}
