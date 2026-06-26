<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Service\AiFacade;
use App\Message\AdvanceMediaJobCommand;
use App\MessageHandler\AdvanceMediaJobCommandHandler;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Sprint A acceptance for the async media-job advancer: each invocation does
 * EXACTLY ONE non-blocking step (no sleep, one provider call per call), the
 * state machine reaches a terminal state, the deadline is enforced, and every
 * failure becomes a localized, non-leaky message instead of an escaping throw.
 */
final class AdvanceMediaJobCommandHandlerTest extends TestCase
{
    private MediaJobService&MockObject $jobService;
    private MediaJobDispatcher&MockObject $dispatcher;
    private MediaJobConfig&MockObject $config;
    private AiFacade&MockObject $aiFacade;
    private LockFactory&MockObject $lockFactory;
    private LockInterface&MockObject $lock;
    private string $uploadDir;
    private AdvanceMediaJobCommandHandler $handler;

    protected function setUp(): void
    {
        $this->jobService = $this->createMock(MediaJobService::class);
        $this->dispatcher = $this->createMock(MediaJobDispatcher::class);
        $this->config = $this->createMock(MediaJobConfig::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = $this->createMock(LockInterface::class);

        $this->config->method('pollIntervalSeconds')->willReturn(3);

        // By default the advance lock is granted; individual tests override.
        $this->lock->method('acquire')->willReturn(true);
        $this->lockFactory->method('createLock')->willReturn($this->lock);

        $this->uploadDir = sys_get_temp_dir().'/synaplan_media_test_'.uniqid('', true);

        $this->handler = new AdvanceMediaJobCommandHandler(
            $this->jobService,
            $this->dispatcher,
            $this->config,
            $this->aiFacade,
            new MediaErrorMessageBuilder(),
            new UserUploadPathBuilder(),
            $this->lockFactory,
            new NullLogger(),
            $this->uploadDir,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            $this->removeDir($this->uploadDir);
        }
    }

    public function testQueuedJobSubmitsThenMarksRunningAndReDispatchesAfterPollInterval(): void
    {
        $job = $this->job(MediaJob::STATUS_QUEUED);
        $job->setPrompt('a cat surfing');
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::once())
            ->method('startVideoGeneration')
            ->with('a cat surfing', 7, self::callback(static fn (array $o): bool => 'higgsfield' === ($o['provider'] ?? null)))
            ->willReturn(['operationName' => 'op-1', 'provider' => 'higgsfield', 'model' => 'dop', 'duration' => 5, 'resolution' => '1080p']);

        $this->jobService->expects(self::once())->method('markSubmitting')->with($job);
        $this->jobService->expects(self::once())->method('markRunning')->with($job, 'op-1');
        $this->dispatcher->expects(self::once())->method('dispatch')->with($job, 3);

        // No polling/downloading on the submit step.
        $this->aiFacade->expects(self::never())->method('pollVideoOperation');
        $this->aiFacade->expects(self::never())->method('downloadVideoRaw');
        $this->jobService->expects(self::never())->method('markFailed');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
    }

    public function testRunningJobStillRenderingUpdatesProgressAndReDispatches(): void
    {
        $job = $this->job(MediaJob::STATUS_RUNNING);
        $job->setProviderRef('op-1');
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::once())
            ->method('pollVideoOperation')
            ->with('op-1', 'higgsfield', 7, self::isType('array'))
            ->willReturn(['done' => false, 'videoUri' => null, 'error' => null, 'status' => 'in_progress', 'percent' => 42]);

        $this->jobService->expects(self::once())->method('updateProgress')->with($job, 42, 'in_progress');
        $this->dispatcher->expects(self::once())->method('dispatch')->with($job, 3);

        $this->jobService->expects(self::never())->method('markFinalizing');
        $this->jobService->expects(self::never())->method('markCompleted');
        $this->jobService->expects(self::never())->method('markFailed');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
    }

    public function testRunningJobDoneMovesToFinalizingImmediately(): void
    {
        $job = $this->job(MediaJob::STATUS_RUNNING);
        $job->setProviderRef('op-1');
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::once())
            ->method('pollVideoOperation')
            ->willReturn(['done' => true, 'videoUri' => 'https://cdn/out.mp4', 'error' => null, 'status' => 'completed', 'percent' => 100]);

        $this->jobService->expects(self::once())->method('updateProgress');
        $this->jobService->expects(self::once())->method('markFinalizing')->with($job);
        // Finalize is scheduled with no delay (delay = 0).
        $this->dispatcher->expects(self::once())->method('dispatch')->with($job, 0);

        $this->aiFacade->expects(self::never())->method('downloadVideoRaw');
        $this->jobService->expects(self::never())->method('markFailed');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        self::assertSame('https://cdn/out.mp4', $job->getOptions()['_outputUri'] ?? null, 'output handle must be stashed for the finalize step');
    }

    public function testFinalizingDownloadsSavesAndMarksCompleted(): void
    {
        $job = $this->job(MediaJob::STATUS_FINALIZING);
        $job->setOptions(['_outputUri' => 'https://cdn/out.mp4']);
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::once())
            ->method('downloadVideoRaw')
            ->with('https://cdn/out.mp4', 'higgsfield', 7, self::isType('array'))
            ->willReturn('RAW-MP4-BYTES');

        $captured = null;
        $this->jobService->expects(self::once())
            ->method('markCompleted')
            ->willReturnCallback(function (MediaJob $j, array $result) use (&$captured): void {
                $captured = $result;
            });

        $this->dispatcher->expects(self::never())->method('dispatch');
        $this->jobService->expects(self::never())->method('markFailed');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        self::assertIsArray($captured);
        self::assertIsArray($captured['file']);
        self::assertSame('video', $captured['file']['type']);
        self::assertSame('video/mp4', $captured['file']['mimeType']);
        self::assertStringStartsWith('/api/v1/files/uploads/', (string) $captured['file']['url']);

        // The bytes really landed on disk under the user's upload tree.
        $relative = substr((string) $captured['file']['url'], strlen('/api/v1/files/uploads/'));
        self::assertFileExists($this->uploadDir.'/'.$relative);
        self::assertSame('RAW-MP4-BYTES', file_get_contents($this->uploadDir.'/'.$relative));
    }

    public function testPollErrorMarksFailedWithLocalizedNonLeakyMessage(): void
    {
        $job = $this->job(MediaJob::STATUS_RUNNING);
        $job->setProviderRef('op-1');
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->method('pollVideoOperation')
            ->willReturn(['done' => false, 'videoUri' => null, 'error' => 'invalid_image_url: could not fetch']);

        $captured = null;
        $this->jobService->expects(self::once())
            ->method('markFailed')
            ->willReturnCallback(function (MediaJob $j, string $message) use (&$captured): void {
                $captured = $message;
            });

        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        self::assertIsString($captured);
        self::assertStringContainsStringIgnoringCase('image', $captured);
        // The raw provider token must never leak to the user-facing message.
        self::assertStringNotContainsString('invalid_image_url', $captured);
    }

    public function testProviderExceptionOnSubmitMarksFailedAndDoesNotThrow(): void
    {
        $job = $this->job(MediaJob::STATUS_QUEUED);
        $job->setPrompt('p');
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->method('startVideoGeneration')
            ->willThrowException(new \RuntimeException('boom'));

        $this->jobService->expects(self::once())->method('markFailed');
        $this->jobService->expects(self::never())->method('markRunning');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
    }

    public function testPastDeadlineJobIsTimedOutAndProviderCancelled(): void
    {
        $job = $this->job(MediaJob::STATUS_RUNNING);
        $job->setProviderRef('op-1');
        $job->setDeadlineAt(time() - 5);
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::once())
            ->method('cancelVideoOperation')
            ->with('op-1', 'higgsfield', 7, self::isType('array'));
        $this->jobService->expects(self::once())->method('markTimedOut')->with($job, self::isType('string'));

        // A timed-out job must not poll or re-dispatch.
        $this->aiFacade->expects(self::never())->method('pollVideoOperation');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
    }

    public function testTerminalJobIsIgnored(): void
    {
        $job = $this->job(MediaJob::STATUS_COMPLETED);
        $this->jobService->method('findByKey')->willReturn($job);

        $this->aiFacade->expects(self::never())->method('startVideoGeneration');
        $this->aiFacade->expects(self::never())->method('pollVideoOperation');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
    }

    public function testMissingJobIsIgnored(): void
    {
        $this->jobService->method('findByKey')->willReturn(null);

        $this->aiFacade->expects(self::never())->method('startVideoGeneration');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->handler->__invoke(new AdvanceMediaJobCommand('does-not-exist'));
    }

    public function testLockNotAcquiredSkipsWithoutTouchingTheJob(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $handler = new AdvanceMediaJobCommandHandler(
            $this->jobService,
            $this->dispatcher,
            $this->config,
            $this->aiFacade,
            new MediaErrorMessageBuilder(),
            new UserUploadPathBuilder(),
            $lockFactory,
            new NullLogger(),
            $this->uploadDir,
        );

        $this->jobService->expects(self::never())->method('findByKey');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $handler->__invoke(new AdvanceMediaJobCommand('locked'));
    }

    private function job(string $status): MediaJob
    {
        $job = new MediaJob();
        $job->setUserId(7)
            ->setType(MediaJob::TYPE_VIDEO)
            ->setProvider('higgsfield')
            ->setModel('dop')
            ->setStatus($status);

        return $job;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
