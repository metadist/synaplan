<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobUsageRecorder;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint E (#1146): a detached media job must record billable usage on
 * completion (the inline path's billing never runs for it). Only `completed`
 * is billed — failed/cancelled/timed-out are never charged (provider refund
 * rules) — and recording is idempotent so a re-sync can't double-bill.
 */
final class MediaJobUsageRecorderTest extends TestCase
{
    private RateLimitService&MockObject $rateLimit;
    private UserRepository&MockObject $users;
    private MediaJobService&MockObject $jobService;
    private MediaJobUsageRecorder $recorder;

    protected function setUp(): void
    {
        $this->rateLimit = $this->createMock(RateLimitService::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->jobService = $this->createMock(MediaJobService::class);
        $this->recorder = new MediaJobUsageRecorder(
            $this->rateLimit,
            $this->users,
            $this->jobService,
            new NullLogger(),
        );
    }

    public function testBillsCompletedVideoOnceWithItsMediaUsage(): void
    {
        $this->users->expects(self::any())->method('find')->with(7)->willReturn($this->createMock(User::class));

        $job = (new MediaJob())
            ->setUserId(7)
            ->setType(MediaJob::TYPE_VIDEO)
            ->setProvider('google')
            ->setModel('veo')
            ->setModelId(195)
            ->setOptions(['media_usage' => ['duration_seconds' => 8.0]])
            ->setStatus(MediaJob::STATUS_COMPLETED);

        $captured = null;
        $this->rateLimit->expects(self::once())
            ->method('recordUsage')
            ->willReturnCallback(function (User $u, string $action, array $meta) use (&$captured): RecordedUsage {
                $captured = ['action' => $action, 'meta' => $meta];

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            });
        // The job is flagged + persisted so a re-sync won't double-bill.
        $this->jobService->expects(self::once())->method('save')->with($job);

        $this->recorder->record($job);

        self::assertSame('VIDEOS', $captured['action']);
        self::assertSame(['duration_seconds' => 8.0], $captured['meta']['media_usage']);
        self::assertTrue($job->getOptions()['_usage_recorded']);
    }

    public function testMapsTypeToBillingAction(): void
    {
        $this->users->method('find')->willReturn($this->createMock(User::class));

        $actions = [];
        $this->rateLimit->method('recordUsage')->willReturnCallback(
            function (User $u, string $action) use (&$actions): RecordedUsage {
                $actions[] = $action;

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            }
        );

        foreach ([MediaJob::TYPE_IMAGE => 'IMAGES', MediaJob::TYPE_AUDIO => 'AUDIOS'] as $type => $expected) {
            $job = (new MediaJob())->setUserId(7)->setType($type)->setProvider('p')->setStatus(MediaJob::STATUS_COMPLETED);
            $this->recorder->record($job);
        }

        self::assertSame(['IMAGES', 'AUDIOS'], $actions);
    }

    public function testDoesNotBillFailedCancelledOrTimedOut(): void
    {
        $this->rateLimit->expects(self::never())->method('recordUsage');

        foreach ([MediaJob::STATUS_FAILED, MediaJob::STATUS_CANCELLED, MediaJob::STATUS_TIMED_OUT] as $status) {
            $job = (new MediaJob())->setUserId(7)->setType(MediaJob::TYPE_VIDEO)->setProvider('google')->setStatus($status);
            $this->recorder->record($job);
        }
    }

    public function testIdempotentWhenAlreadyRecorded(): void
    {
        $this->rateLimit->expects(self::never())->method('recordUsage');

        $job = (new MediaJob())
            ->setUserId(7)
            ->setType(MediaJob::TYPE_VIDEO)
            ->setProvider('google')
            ->setOptions(['_usage_recorded' => true])
            ->setStatus(MediaJob::STATUS_COMPLETED);

        $this->recorder->record($job);
    }

    public function testDoesNotBillAnonymousOrUnknownUser(): void
    {
        $this->rateLimit->expects(self::never())->method('recordUsage');

        $anon = (new MediaJob())->setUserId(0)->setType(MediaJob::TYPE_VIDEO)->setProvider('g')->setStatus(MediaJob::STATUS_COMPLETED);
        $this->recorder->record($anon);

        $this->users->expects(self::any())->method('find')->with(99)->willReturn(null);
        $missing = (new MediaJob())->setUserId(99)->setType(MediaJob::TYPE_VIDEO)->setProvider('g')->setStatus(MediaJob::STATUS_COMPLETED);
        $this->recorder->record($missing);
    }
}
