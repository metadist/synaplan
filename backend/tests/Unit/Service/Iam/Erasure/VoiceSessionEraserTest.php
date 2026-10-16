<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\VoiceSession;
use App\Repository\VoiceSessionRepository;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Iam\Erasure\VoiceSessionEraser;
use App\Service\Voice\VoiceLiveConcurrency;
use PHPUnit\Framework\TestCase;

/**
 * Voice session rows go inside the transaction; an open session's provider
 * slot is released only after the commit.
 */
final class VoiceSessionEraserTest extends TestCase
{
    public function testAnOpenSessionReleasesItsProviderSlotAfterTheCommit(): void
    {
        $open = (new VoiceSession())->setUserId(42)->setProvider('openai')->setStatus('live');
        $sessions = $this->createMock(VoiceSessionRepository::class);
        $sessions->method('findOpenForUser')->willReturnMap([[42, $open]]);
        $sessions->expects(self::once())->method('deleteByUser')->with(42);
        $released = [];
        $concurrency = $this->createStub(VoiceLiveConcurrency::class);
        $concurrency->method('release')->willReturnCallback(static function (string $provider) use (&$released): void {
            $released[] = $provider;
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new VoiceSessionEraser($sessions, $concurrency))->erase($erasure);

        self::assertSame([], $released, 'The slot may only be released after the commit.');
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
        self::assertSame(['openai'], $released);
    }

    public function testEndedSessionsAreDeletedWithoutTouchingAProviderSlot(): void
    {
        $sessions = $this->createMock(VoiceSessionRepository::class);
        $sessions->method('findOpenForUser')->willReturn(null);
        $sessions->expects(self::once())->method('deleteByUser')->with(42);

        $erasure = new UserErasure(42, 'ada@example.test');
        (new VoiceSessionEraser($sessions, $this->createStub(VoiceLiveConcurrency::class)))->erase($erasure);

        self::assertSame([], $erasure->pendingCleanups());
    }
}
