<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Repository\DesktopDeviceRepository;
use App\Repository\DesktopJobRepository;
use App\Service\Iam\Erasure\DesktopEraser;
use App\Service\Iam\Erasure\UserErasure;
use PHPUnit\Framework\TestCase;

/**
 * A paired computer's jobs go before the device itself, both inside the
 * deletion transaction.
 */
final class DesktopEraserTest extends TestCase
{
    public function testDeletesTheJobsBeforeTheDevices(): void
    {
        $order = [];
        $jobs = $this->createMock(DesktopJobRepository::class);
        $jobs->expects(self::once())->method('deleteByOwner')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'jobs';
            });
        $devices = $this->createMock(DesktopDeviceRepository::class);
        $devices->expects(self::once())->method('deleteByOwner')->with(42)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'devices';
            });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new DesktopEraser($jobs, $devices))->erase($erasure);

        self::assertSame(['jobs', 'devices'], $order);
        self::assertSame([], $erasure->pendingCleanups());
    }
}
