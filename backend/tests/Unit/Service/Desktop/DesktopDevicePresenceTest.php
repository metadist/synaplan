<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\DesktopDevice;
use App\Service\Desktop\DesktopDevicePresence;
use App\Service\Desktop\DesktopJobContract;
use PHPUnit\Framework\TestCase;

final class DesktopDevicePresenceTest extends TestCase
{
    public function testCheckInBoundMatchesTheIdlePollHint(): void
    {
        self::assertSame(DesktopJobContract::NEXT_CALL_IDLE_SECONDS, DesktopDevicePresence::CHECK_IN_SECONDS);
        self::assertSame(180, DesktopDevicePresence::CHECK_IN_SECONDS);
    }

    public function testRevokedKeyIsNeverOnline(): void
    {
        $now = 1_700_000_000;

        self::assertSame(
            DesktopDevicePresence::REVOKED,
            DesktopDevicePresence::resolve(DesktopDevice::STATUS_REVOKED, $now, $now),
        );
    }

    public function testActiveKeyThatNeverCheckedInIsNotConnected(): void
    {
        self::assertSame(
            DesktopDevicePresence::NEVER,
            DesktopDevicePresence::resolve(DesktopDevice::STATUS_ACTIVE, 0, 1_700_000_000),
        );
    }

    public function testCheckInInsideTheIdleWindowIsOnline(): void
    {
        $now = 1_700_000_000;
        $lastSeen = $now - DesktopDevicePresence::CHECK_IN_SECONDS;

        self::assertSame(
            DesktopDevicePresence::ONLINE,
            DesktopDevicePresence::resolve(DesktopDevice::STATUS_ACTIVE, $lastSeen, $now),
        );
    }

    public function testCheckInOlderThanTheIdleWindowIsAway(): void
    {
        $now = 1_700_000_000;
        $lastSeen = $now - DesktopDevicePresence::CHECK_IN_SECONDS - 1;

        self::assertSame(
            DesktopDevicePresence::AWAY,
            DesktopDevicePresence::resolve(DesktopDevice::STATUS_ACTIVE, $lastSeen, $now),
        );
    }
}
