<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramTypingPulse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TelegramTypingPulseTest extends TestCase
{
    public function testBeatSendsAtMostEveryFourSeconds(): void
    {
        $clock = new MockClock('2026-09-30 12:00:00');
        $sent = 0;
        $pulse = new TelegramTypingPulse(
            $clock,
            static function () use (&$sent): void {
                ++$sent;
            },
            static function (): void {
                self::fail('A successful beat is not a delivery failure.');
            },
        );

        $pulse->beat();
        $pulse->beat();
        $this->assertSame(1, $sent);

        $clock->sleep(3);
        $pulse->beat();
        $this->assertSame(1, $sent);

        $clock->sleep(1);
        $pulse->beat();
        $this->assertSame(2, $sent);
    }

    public function testRevokedTokenStopsThePulse(): void
    {
        $clock = new MockClock('2026-09-30 12:00:00');
        $sent = 0;
        $failures = 0;
        $pulse = new TelegramTypingPulse(
            $clock,
            static function () use (&$sent): void {
                ++$sent;
                throw new TelegramChannelException(TelegramChannelException::TOKEN_REVOKED);
            },
            static function (TelegramChannelException $e) use (&$failures): void {
                ++$failures;
                self::assertSame(TelegramChannelException::TOKEN_REVOKED, $e->errorCode);
            },
        );

        $pulse->beat();
        $clock->sleep(4);
        $pulse->beat();

        $this->assertSame(1, $sent);
        $this->assertSame(1, $failures);
    }

    public function testATemporaryFailureDoesNotStopThePulse(): void
    {
        $clock = new MockClock('2026-09-30 12:00:00');
        $sent = 0;
        $pulse = new TelegramTypingPulse(
            $clock,
            static function () use (&$sent): void {
                ++$sent;
                if (1 === $sent) {
                    throw new TelegramChannelException(TelegramChannelException::SEND_FAILED);
                }
            },
            static function (): void {
            },
        );

        $pulse->beat();
        $pulse->beat();
        $this->assertSame(1, $sent);

        $clock->sleep(4);
        $pulse->beat();

        $this->assertSame(2, $sent);
    }
}
