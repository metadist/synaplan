<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MediaJobServiceTest extends TestCase
{
    private MediaJobStore&\PHPUnit\Framework\MockObject\Stub $store;
    private MediaJobService $service;

    protected function setUp(): void
    {
        $this->store = $this->createStub(MediaJobStore::class);
        $this->service = new MediaJobService($this->store, new NullLogger());
    }

    public function testCreateStagesQueuedJobWithDeadlineAndKey(): void
    {
        $job = $this->service->create([
            'userId' => 42,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'higgsfield',
            'prompt' => 'a cat surfing',
            'messageId' => 7,
            'nodeId' => 'n1',
            'options' => ['resolution' => '1080p'],
        ]);

        self::assertSame(MediaJob::STATUS_QUEUED, $job->getStatus());
        self::assertFalse($job->isTerminal());
        self::assertSame(42, $job->getUserId());
        self::assertSame('higgsfield', $job->getProvider());
        self::assertSame('a cat surfing', $job->getPrompt());
        self::assertSame(7, $job->getMessageId());
        self::assertSame('n1', $job->getNodeId());
        self::assertSame(['resolution' => '1080p'], $job->getOptions());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $job->getJobKey());
        self::assertNotNull($job->getDeadlineAt());
        self::assertGreaterThan(time() + 600, (int) $job->getDeadlineAt());
    }

    public function testHappyPathTransitionsToCompleted(): void
    {
        $job = $this->service->create([
            'userId' => 1,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'higgsfield',
        ]);

        $this->service->markSubmitting($job);
        self::assertSame(MediaJob::STATUS_SUBMITTING, $job->getStatus());
        self::assertNotNull($job->getStartedAt());

        $this->service->markRunning($job, 'req-123');
        self::assertSame(MediaJob::STATUS_RUNNING, $job->getStatus());
        self::assertSame('req-123', $job->getProviderRef());

        $this->service->updateProgress($job, 55, 'in_progress');
        self::assertSame(55, $job->getPercent());
        self::assertSame('in_progress', $job->getProviderStatus());
        self::assertFalse($job->isTerminal());

        $this->service->markFinalizing($job);
        self::assertSame(MediaJob::STATUS_FINALIZING, $job->getStatus());

        $file = ['url' => '/api/v1/files/uploads/x.mp4', 'type' => 'video', 'mimeType' => 'video/mp4'];
        $this->service->markCompleted($job, ['file' => $file]);

        self::assertSame(MediaJob::STATUS_COMPLETED, $job->getStatus());
        self::assertTrue($job->isTerminal());
        self::assertSame(100, $job->getPercent());
        self::assertNotNull($job->getFinishedAt());

        $status = $this->service->toStatusArray($job);
        self::assertSame('done', $status['state']);
        self::assertTrue($status['finished']);
        self::assertSame($file, $status['file']);
    }

    public function testFailedAndTimedOutMapToFailedClientState(): void
    {
        $failed = $this->service->create(['userId' => 1, 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'p']);
        $this->service->markFailed($failed, 'provider exploded');
        self::assertSame(MediaJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame('failed', $this->service->toStatusArray($failed)['state']);
        self::assertSame('provider exploded', $failed->getError());

        $timedOut = $this->service->create(['userId' => 1, 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'p']);
        $this->service->markTimedOut($timedOut, 'deadline exceeded');
        self::assertSame(MediaJob::STATUS_TIMED_OUT, $timedOut->getStatus());
        self::assertSame('failed', $this->service->toStatusArray($timedOut)['state']);
        self::assertTrue($timedOut->isTerminal());
    }

    public function testCancelledMapsToCancelledClientState(): void
    {
        $job = $this->service->create(['userId' => 1, 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'p']);
        $this->service->markCancelled($job);

        self::assertSame(MediaJob::STATUS_CANCELLED, $job->getStatus());
        self::assertTrue($job->isTerminal());
        self::assertSame('cancelled', $this->service->toStatusArray($job)['state']);
    }

    public function testPercentIsClampedToValidRange(): void
    {
        $job = new MediaJob();
        $job->setPercent(150);
        self::assertSame(100, $job->getPercent());

        $job->setPercent(-10);
        self::assertSame(0, $job->getPercent());

        $job->setPercent(null);
        self::assertNull($job->getPercent());
    }

    public function testIsPastDeadline(): void
    {
        $job = new MediaJob();
        self::assertFalse($job->isPastDeadline(), 'no deadline set means never past deadline');

        $job->setDeadlineAt(time() - 5);
        self::assertTrue($job->isPastDeadline());

        $job->setDeadlineAt(time() + 1000);
        self::assertFalse($job->isPastDeadline());
    }

    public function testVideoDeadlineIsLongerThanImage(): void
    {
        self::assertGreaterThan(
            $this->service->deadlineSecondsFor(MediaJob::TYPE_IMAGE),
            $this->service->deadlineSecondsFor(MediaJob::TYPE_VIDEO),
        );
    }
}
