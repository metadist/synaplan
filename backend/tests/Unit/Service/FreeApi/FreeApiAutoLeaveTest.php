<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiAutoLeave;
use App\Service\FreeApi\FreeApiLeave;
use App\Service\FreeApi\FreeApiState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FreeApiAutoLeaveTest extends TestCase
{
    public function testDoesNothingWhileTheDemoIsInactive(): void
    {
        $activator = $this->createMock(FreeApiActivator::class);
        $activator->expects(self::never())->method('leave');

        self::assertFalse($this->autoLeave(false, $activator)->ownProviderConnected('openai'));
    }

    public function testLeavesAnActiveDemo(): void
    {
        $activator = $this->createMock(FreeApiActivator::class);
        $activator->expects(self::once())->method('leave')->willReturn(new FreeApiLeave(true, ['CHAT'], true));

        self::assertTrue($this->autoLeave(true, $activator)->ownProviderConnected('openai'));
    }

    public function testAFailedLeaveNeverBreaksSavingTheProvider(): void
    {
        $activator = $this->createStub(FreeApiActivator::class);
        $activator->method('leave')->willThrowException(new \RuntimeException('database gone'));

        self::assertFalse($this->autoLeave(true, $activator)->ownProviderConnected('openai'));
    }

    private function autoLeave(bool $active, FreeApiActivator $activator): FreeApiAutoLeave
    {
        $state = $this->createStub(FreeApiState::class);
        $state->method('isActive')->willReturn($active);

        return new FreeApiAutoLeave($state, $activator, new NullLogger());
    }
}
