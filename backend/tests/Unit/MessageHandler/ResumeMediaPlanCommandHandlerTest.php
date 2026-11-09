<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\ResumeMediaPlanCommand;
use App\MessageHandler\ResumeMediaPlanCommandHandler;
use App\Service\Media\MediaDependentPlanContinuation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class ResumeMediaPlanCommandHandlerTest extends TestCase
{
    public function testABusyPlanIsQueuedAgain(): void
    {
        $command = new ResumeMediaPlanCommand(1001, 'n2', 'job-2');
        $continuation = $this->createStub(MediaDependentPlanContinuation::class);
        $continuation->method('continue')->willReturn(false);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            $command,
            self::callback(static function (array $stamps): bool {
                return 1 === count($stamps)
                    && $stamps[0] instanceof DelayStamp
                    && 2000 === $stamps[0]->getDelay();
            }),
        )->willReturn(new Envelope($command));

        (new ResumeMediaPlanCommandHandler($continuation, $bus, new NullLogger()))($command);
    }

    public function testAFinishedResumeIsNotQueuedAgain(): void
    {
        $command = new ResumeMediaPlanCommand(1001, 'n1', 'job-1');
        $continuation = $this->createStub(MediaDependentPlanContinuation::class);
        $continuation->method('continue')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new ResumeMediaPlanCommandHandler($continuation, $bus, new NullLogger()))($command);
    }
}
