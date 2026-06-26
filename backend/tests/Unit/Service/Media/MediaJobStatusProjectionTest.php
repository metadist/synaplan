<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaJobStatusProjectionTest extends TestCase
{
    public function testToStatusArrayIncludesWaitBudgetFields(): void
    {
        $store = $this->createStub(MediaJobStore::class);
        $service = new MediaJobService($store, new NullLogger());

        $job = $service->create([
            'userId' => 1,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
        ]);
        $service->markRunning($job, 'op-1');
        $service->updateProgress($job, 42, 'processing');

        $status = $service->toStatusArray($job);

        self::assertSame('running', $status['state']);
        self::assertSame(42, $status['percent']);
        self::assertSame(1200, $status['max_wait_seconds']);
        self::assertNotNull($status['deadline_at']);
        self::assertIsInt($status['remaining_seconds']);
        self::assertGreaterThanOrEqual(0, $status['elapsed_seconds']);
    }

    public function testEnforceDeadlineMarksOverdueJobTimedOut(): void
    {
        $store = $this->createMock(MediaJobStore::class);
        $store->expects(self::atLeastOnce())->method('save');
        $service = new MediaJobService($store, new NullLogger());

        $job = $service->create([
            'userId' => 1,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
        ]);
        $job->setDeadlineAt(time() - 10);
        $service->markRunning($job, 'op-1');

        self::assertTrue($service->enforceDeadline($job, 'Timed out for test'));
        self::assertSame(MediaJob::STATUS_TIMED_OUT, $job->getStatus());
        self::assertSame('failed', $service->toStatusArray($job)['state']);
    }

    public function testEnforceDeadlineIsNoOpForTerminalJob(): void
    {
        $store = $this->createStub(MediaJobStore::class);
        $service = new MediaJobService($store, new NullLogger());

        $job = $service->create([
            'userId' => 1,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
        ]);
        $job->setDeadlineAt(time() - 10);
        $service->markFailed($job, 'already failed');

        self::assertFalse($service->enforceDeadline($job, 'Timed out for test'));
    }
}
