<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobReaper;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The reaper is the "no job runs forever" backstop for renders whose worker
 * died: a stale-heartbeat active job is driven to `timed_out` and the provider
 * operation is best-effort cancelled so we stop being billed.
 */
final class MediaJobReaperTest extends TestCase
{
    private MediaJobService&MockObject $jobService;
    private MediaJobMessageSync&MockObject $messageSync;
    private MediaJobConfig&MockObject $config;
    private AiFacade&MockObject $aiFacade;
    private MediaErrorMessageBuilder $errorBuilder;
    private MediaJobReaper $reaper;

    protected function setUp(): void
    {
        $this->jobService = $this->createMock(MediaJobService::class);
        $this->messageSync = $this->createMock(MediaJobMessageSync::class);
        $this->config = $this->createMock(MediaJobConfig::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->errorBuilder = new MediaErrorMessageBuilder();
        $this->config->method('heartbeatStaleSeconds')->willReturn(90);

        $this->reaper = new MediaJobReaper(
            $this->jobService,
            $this->messageSync,
            $this->config,
            $this->aiFacade,
            $this->errorBuilder,
            new NullLogger(),
        );
    }

    public function testTimesOutStaleJobAndCancelsProvider(): void
    {
        $job = (new MediaJob())
            ->setUserId(7)
            ->setProvider('higgsfield')
            ->setProviderRef('op-1')
            ->setStatus(MediaJob::STATUS_RUNNING);

        $this->jobService->method('findStale')->willReturn([$job]);
        $this->jobService->method('findPastDeadline')->willReturn([]);
        $this->jobService->method('langFromJob')->willReturn('en');

        $this->aiFacade->expects(self::once())
            ->method('cancelVideoOperation')
            ->with('op-1', 'higgsfield', 7, self::isType('array'));
        $this->jobService->expects(self::once())
            ->method('markTimedOut')
            ->with($job, self::isType('string'));
        $this->messageSync->expects(self::once())->method('syncTerminalState')->with($job);

        self::assertSame(1, $this->reaper->reap());
    }

    public function testTimesOutPastDeadlineJobEvenWithFreshHeartbeat(): void
    {
        $job = (new MediaJob())
            ->setUserId(7)
            ->setProvider('google')
            ->setProviderRef('op-2')
            ->setStatus(MediaJob::STATUS_RUNNING)
            ->setDeadlineAt(time() - 30);

        $this->jobService->method('findStale')->willReturn([]);
        $this->jobService->method('findPastDeadline')->willReturn([$job]);
        $this->jobService->method('langFromJob')->willReturn('en');

        $this->jobService->expects(self::once())->method('markTimedOut')->with($job, self::isType('string'));
        $this->messageSync->expects(self::once())->method('syncTerminalState')->with($job);

        self::assertSame(1, $this->reaper->reap());
    }

    public function testHeartbeatCutoffUsesConfiguredStaleWindow(): void
    {
        $this->config = $this->createMock(MediaJobConfig::class);
        $this->config->method('heartbeatStaleSeconds')->willReturn(120);
        $reaper = new MediaJobReaper(
            $this->jobService,
            $this->messageSync,
            $this->config,
            $this->aiFacade,
            $this->errorBuilder,
            new NullLogger(),
        );

        $before = time();
        $this->jobService->expects(self::once())
            ->method('findStale')
            ->with(self::callback(function (int $cutoff) use ($before): bool {
                // cutoff ≈ now - 120s (allow a second of clock drift during the call).
                return $cutoff <= $before - 120 + 1 && $cutoff >= $before - 120 - 2;
            }))
            ->willReturn([]);
        $this->jobService->method('findPastDeadline')->willReturn([]);

        self::assertSame(0, $reaper->reap());
    }

    public function testSkipsAJobThatWentTerminalBetweenScanAndReap(): void
    {
        $terminal = (new MediaJob())->setStatus(MediaJob::STATUS_COMPLETED);
        $this->jobService->method('findStale')->willReturn([$terminal]);
        $this->jobService->method('findPastDeadline')->willReturn([]);

        $this->aiFacade->expects(self::never())->method('cancelVideoOperation');
        $this->jobService->expects(self::never())->method('markTimedOut');

        self::assertSame(0, $this->reaper->reap());
    }

    public function testNoStaleJobsReturnsZero(): void
    {
        $this->jobService->method('findStale')->willReturn([]);
        $this->jobService->method('findPastDeadline')->willReturn([]);

        $this->jobService->expects(self::never())->method('markTimedOut');

        self::assertSame(0, $this->reaper->reap());
    }
}
