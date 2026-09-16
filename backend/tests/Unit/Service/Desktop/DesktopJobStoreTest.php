<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\DesktopDevice;
use App\Entity\DesktopJob;
use App\Repository\DesktopJobRepository;
use App\Service\Desktop\DesktopJobStore;
use App\Service\Desktop\Exception\ResultTooLargeException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DesktopJobStoreTest extends TestCase
{
    private DesktopJobRepository&MockObject $jobRepository;
    private EntityManagerInterface&MockObject $em;
    private DesktopJobStore $store;

    protected function setUp(): void
    {
        $this->jobRepository = $this->createMock(DesktopJobRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->store = new DesktopJobStore($this->jobRepository, $this->em);
    }

    public function testEnqueueReturnsExistingJobForKnownIdempotencyKey(): void
    {
        $existing = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_QUEUED);
        $this->jobRepository->expects(self::once())
            ->method('findByOwnerIdempotency')
            ->with(1, 'idem-1')
            ->willReturn($existing);
        // No new job is persisted when the idempotency key is already known.
        $this->jobRepository->expects(self::never())->method('save');

        $job = $this->store->enqueueSkillRun(1, null, 'pptx', 'hi', [], null, null, 'idem-1');

        self::assertSame($existing, $job);
    }

    public function testEnqueueBuildsSanitizedSkillRunJob(): void
    {
        $this->jobRepository->method('findByOwnerIdempotency')->willReturn(null);
        $this->jobRepository->expects(self::once())->method('save');

        $job = $this->store->enqueueSkillRun(9, 3, 'notes', 'Summarize', [5, 6], 42, 100, null);

        self::assertSame(9, $job->getOwnerId());
        self::assertSame(3, $job->getDeviceId());
        self::assertSame(DesktopJob::TYPE_SKILL_RUN, $job->getType());
        self::assertSame(DesktopJob::STATUS_QUEUED, $job->getStatus());
        self::assertSame('notes', $job->getInput()['skill']);
        self::assertSame([5, 6], $job->getInput()['fileIds']);
        self::assertSame(42, $job->getChatId());
        self::assertSame(100, $job->getMessageId());
        self::assertNull($job->getIdempotency());
    }

    public function testEnqueueClampsOverlongPrompt(): void
    {
        $this->jobRepository->method('findByOwnerIdempotency')->willReturn(null);
        $this->jobRepository->method('save');

        $long = str_repeat('x', DesktopJobStore::PROMPT_MAX_CHARS + 500);
        $job = $this->store->enqueueSkillRun(1, null, 'pptx', $long);

        self::assertSame(DesktopJobStore::PROMPT_MAX_CHARS, mb_strlen($job->getInput()['prompt']));
    }

    public function testLeaseForDeviceMarksJobLeasedWithToken(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_QUEUED);

        // wrapInTransaction just runs the callback in the unit test.
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $this->jobRepository->expects(self::once())
            ->method('findNextLeasable')
            ->with(1, 4)
            ->willReturn($job);
        $this->em->expects(self::once())->method('flush');

        $leased = $this->store->leaseForDevice($device);

        self::assertNotNull($leased);
        self::assertSame(DesktopJob::STATUS_LEASED, $leased->getStatus());
        self::assertSame(4, $leased->getDeviceId());
        self::assertNotNull($leased->getLeaseToken());
        self::assertStringStartsWith('lt_', (string) $leased->getLeaseToken());
        self::assertGreaterThan(time(), $leased->getLeaseExpires());
        self::assertSame(1, $leased->getAttempt());
    }

    public function testLeaseForDeviceReturnsNullWhenNothingQueued(): void
    {
        $device = self::device(4, 1);
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $this->jobRepository->method('findNextLeasable')->willReturn(null);

        self::assertNull($this->store->leaseForDevice($device));
    }

    public function testReportResultRejectsUnknownLeaseToken(): void
    {
        $device = self::device(4, 1);
        $this->jobRepository->method('findByLeaseToken')->willReturn(null);

        self::assertNull($this->store->reportResult($device, 'lt_nope', DesktopJob::STATUS_SUCCEEDED));
    }

    public function testReportResultRejectsForeignOwner(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(999)->setStatus(DesktopJob::STATUS_LEASED)->setLeaseToken('lt_x');
        $this->jobRepository->method('findByLeaseToken')->willReturn($job);

        self::assertNull($this->store->reportResult($device, 'lt_x', DesktopJob::STATUS_SUCCEEDED));
    }

    public function testReportResultRejectsNonLeasedJob(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_QUEUED)->setLeaseToken('lt_x');
        $this->jobRepository->method('findByLeaseToken')->willReturn($job);

        self::assertNull($this->store->reportResult($device, 'lt_x', DesktopJob::STATUS_SUCCEEDED));
    }

    public function testReportResultRejectsInvalidStatus(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_LEASED)->setLeaseToken('lt_x');
        $this->jobRepository->method('findByLeaseToken')->willReturn($job);

        self::assertNull($this->store->reportResult($device, 'lt_x', 'queued'));
    }

    public function testReportResultStoresSuccessAndClearsLease(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_LEASED)->setLeaseToken('lt_x');
        $this->jobRepository->method('findByLeaseToken')->willReturn($job);
        $this->jobRepository->expects(self::once())->method('save');

        $updated = $this->store->reportResult($device, 'lt_x', DesktopJob::STATUS_SUCCEEDED, ['fileIds' => [1]], null);

        self::assertNotNull($updated);
        self::assertSame(DesktopJob::STATUS_SUCCEEDED, $updated->getStatus());
        self::assertSame(['fileIds' => [1]], $updated->getResult());
        self::assertNull($updated->getLeaseToken());
    }

    public function testReportResultRejectsOversizedResult(): void
    {
        $device = self::device(4, 1);
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_LEASED)->setLeaseToken('lt_x');
        $this->jobRepository->method('findByLeaseToken')->willReturn($job);

        $huge = ['blob' => str_repeat('a', DesktopJobStore::RESULT_MAX_BYTES + 10)];

        $this->expectException(ResultTooLargeException::class);
        $this->store->reportResult($device, 'lt_x', DesktopJob::STATUS_FAILED, $huge, 'local_error');
    }

    public function testRequeueExpiredLeasesRequeuesUnderAttemptBudget(): void
    {
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_LEASED)
            ->setAttempt(1)->setMaxAttempts(3)->setLeaseToken('lt_x');
        $this->jobRepository->method('findExpiredLeases')->willReturn([$job]);
        $this->em->expects(self::once())->method('flush');

        $result = $this->store->requeueExpiredLeases();

        self::assertSame(['requeued' => 1, 'failed' => 0], $result);
        self::assertSame(DesktopJob::STATUS_QUEUED, $job->getStatus());
        self::assertNull($job->getLeaseToken());
        self::assertSame(0, $job->getLeaseExpires());
    }

    public function testRequeueExpiredLeasesFailsWhenAttemptsExhausted(): void
    {
        $job = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_LEASED)
            ->setAttempt(3)->setMaxAttempts(3)->setLeaseToken('lt_x');
        $this->jobRepository->method('findExpiredLeases')->willReturn([$job]);

        $result = $this->store->requeueExpiredLeases();

        self::assertSame(['requeued' => 0, 'failed' => 1], $result);
        self::assertSame(DesktopJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('timeout', $job->getErrorCode());
    }

    public function testRequeueExpiredLeasesNoOpWhenNoneExpired(): void
    {
        $this->jobRepository->method('findExpiredLeases')->willReturn([]);
        $this->em->expects(self::never())->method('flush');

        self::assertSame(['requeued' => 0, 'failed' => 0], $this->store->requeueExpiredLeases());
    }

    private static function device(int $id, int $ownerId): DesktopDevice
    {
        $device = (new DesktopDevice())->setOwnerId($ownerId)->setStatus(DesktopDevice::STATUS_ACTIVE);
        $ref = new \ReflectionProperty(DesktopDevice::class, 'id');
        $ref->setValue($device, $id);

        return $device;
    }
}
