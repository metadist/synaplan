<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobCanceller;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint D: user-initiated cancel. Drives an active job to `cancelled`,
 * best-effort tells the provider to stop (async video only — synchronous
 * image/audio have no operation handle), and syncs the message (which also
 * pushes the terminal state). Already-terminal jobs are a no-op.
 */
final class MediaJobCancellerTest extends TestCase
{
    private MediaJobService&MockObject $jobService;
    private MediaJobMessageSync&MockObject $messageSync;
    private AiFacade&MockObject $aiFacade;
    private MediaJobCanceller $canceller;

    protected function setUp(): void
    {
        $this->jobService = $this->createMock(MediaJobService::class);
        $this->messageSync = $this->createMock(MediaJobMessageSync::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->canceller = new MediaJobCanceller(
            $this->jobService,
            $this->messageSync,
            $this->aiFacade,
            new NullLogger(),
        );
    }

    public function testCancelsRunningVideoJobAndTellsProviderToStop(): void
    {
        $job = (new MediaJob())
            ->setUserId(7)
            ->setType(MediaJob::TYPE_VIDEO)
            ->setProvider('google')
            ->setProviderRef('op-1')
            ->setStatus(MediaJob::STATUS_RUNNING);

        $this->aiFacade->expects(self::once())
            ->method('cancelVideoOperation')
            ->with('op-1', 'google', 7, self::isType('array'));
        $this->jobService->expects(self::once())->method('markCancelled')->with($job);
        $this->messageSync->expects(self::once())->method('syncTerminalState')->with($job);

        self::assertTrue($this->canceller->cancel($job));
    }

    public function testCancelsSynchronousImageJobWithoutProviderCall(): void
    {
        $job = (new MediaJob())
            ->setUserId(7)
            ->setType(MediaJob::TYPE_IMAGE)
            ->setProvider('openai')
            ->setStatus(MediaJob::STATUS_QUEUED);

        // No provider operation handle for synchronous media.
        $this->aiFacade->expects(self::never())->method('cancelVideoOperation');
        $this->jobService->expects(self::once())->method('markCancelled')->with($job);
        $this->messageSync->expects(self::once())->method('syncTerminalState')->with($job);

        self::assertTrue($this->canceller->cancel($job));
    }

    public function testAlreadyTerminalJobIsNoOp(): void
    {
        $job = (new MediaJob())->setType(MediaJob::TYPE_VIDEO)->setStatus(MediaJob::STATUS_COMPLETED);

        $this->aiFacade->expects(self::never())->method('cancelVideoOperation');
        $this->jobService->expects(self::never())->method('markCancelled');
        $this->messageSync->expects(self::never())->method('syncTerminalState');

        self::assertFalse($this->canceller->cancel($job));
    }
}
