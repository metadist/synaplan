<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Service\Chat\Stream\AttachToken;
use PHPUnit\Framework\TestCase;

final class AttachTokenTest extends TestCase
{
    private const VECTOR = 'cnVuLTEKdXNlci03CjAKMTcwMDAwMDAwMA.5_VeI1_xcbothpgKhr1hw-QlKQGRK3YvY2k30tyu1Mc';

    public function testSignMatchesTheWatchdogVector(): void
    {
        self::assertSame(self::VECTOR, AttachToken::sign('test-secret', 'run-1', 'user-7', 0, 1700000000));
    }

    public function testVerifyRejectsAWidenedCursorAndAnExpiredToken(): void
    {
        self::assertTrue(AttachToken::verify('test-secret', self::VECTOR, 'run-1', 'user-7', 0, 1700000000));
        self::assertFalse(AttachToken::verify('test-secret', self::VECTOR, 'run-1', 'user-7', 1, 1700000000));
        self::assertFalse(AttachToken::verify('test-secret', self::VECTOR, 'run-1', 'user-7', 0, 1700000001));
        self::assertFalse(AttachToken::verify('other-secret', self::VECTOR, 'run-1', 'user-7', 0, 1700000000));
    }
}
