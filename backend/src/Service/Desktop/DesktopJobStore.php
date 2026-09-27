<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\DesktopDevice;
use App\Entity\DesktopJob;
use App\Repository\DesktopJobRepository;
use App\Service\Desktop\Exception\ResultTooLargeException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enqueue / lease / expire / report lifecycle for {@see DesktopJob} rows.
 *
 * Modelled on the media-job store (lease + heartbeat), but DB-backed because
 * the queue must survive across the device's sleep between check-ins and be
 * cluster-visible. Leasing is atomic via a pessimistic row lock
 * ({@see DesktopJobRepository::findNextLeasable()}) inside a transaction, so
 * two check-ins can never lease the same job.
 */
final class DesktopJobStore
{
    /** How long a leased job is reserved before the reaper may requeue it. */
    public const LEASE_TTL_SECONDS = 300;

    /**
     * How long a job may sit `queued` (never leased, or returned to the queue)
     * before the reaper fails it. A closed app can still pick the job up the
     * same day; it does not wait forever.
     */
    public const QUEUED_TTL_SECONDS = 86400;

    /** Cap on the stored result JSON — untrusted input re-entering the account. */
    public const RESULT_MAX_BYTES = 65536;

    /** Cap on the enqueued prompt length. */
    public const PROMPT_MAX_CHARS = 8000;

    public function __construct(
        private readonly DesktopJobRepository $jobRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Enqueue a `skill.run` job. Idempotent: if $idempotency is given and a job
     * already exists for (owner, idempotency), the existing job is returned
     * unchanged.
     *
     * @param list<int> $fileIds
     */
    public function enqueueSkillRun(
        int $ownerId,
        ?int $deviceId,
        string $skill,
        string $prompt,
        array $fileIds = [],
        ?int $chatId = null,
        ?int $messageId = null,
        ?string $idempotency = null,
    ): DesktopJob {
        if (null !== $idempotency && '' !== $idempotency) {
            $existing = $this->jobRepository->findByOwnerIdempotency($ownerId, $idempotency);
            if (null !== $existing) {
                return $existing;
            }
        }

        $job = (new DesktopJob())
            ->setOwnerId($ownerId)
            ->setDeviceId($deviceId)
            ->setType(DesktopJob::TYPE_SKILL_RUN)
            ->setStatus(DesktopJob::STATUS_QUEUED)
            ->setInput([
                'skill' => $skill,
                'prompt' => mb_substr($prompt, 0, self::PROMPT_MAX_CHARS),
                'fileIds' => array_map('intval', $fileIds),
            ])
            ->setChatId($chatId)
            ->setMessageId($messageId)
            ->setIdempotency('' !== (string) $idempotency ? $idempotency : null);

        $this->jobRepository->save($job);

        return $job;
    }

    /**
     * Atomically lease the next job for a device, or null if none is waiting.
     * The returned job is `leased` with a fresh lease token and expiry.
     */
    public function leaseForDevice(DesktopDevice $device): ?DesktopJob
    {
        $deviceId = (int) $device->getId();
        $ownerId = $device->getOwnerId();

        /** @var DesktopJob|null $leased */
        $leased = $this->em->wrapInTransaction(function () use ($ownerId, $deviceId): ?DesktopJob {
            $job = $this->jobRepository->findNextLeasable($ownerId, $deviceId);
            if (null === $job) {
                return null;
            }

            $now = time();
            $job->setStatus(DesktopJob::STATUS_LEASED)
                ->setDeviceId($deviceId)
                ->setLeaseToken(self::generateLeaseToken())
                ->setLeaseExpires($now + self::LEASE_TTL_SECONDS)
                ->setAttempt($job->getAttempt() + 1)
                ->touch();

            $this->em->flush();

            return $job;
        });

        return $leased;
    }

    /**
     * Record a device's result for a leased job.
     *
     * @param array<string, mixed>|null $result
     *
     * @return DesktopJob|null the updated job, or null when the lease token is
     *                         unknown / stale / not owned by this device (→ 400)
     *
     * @throws ResultTooLargeException when the result JSON exceeds the size cap
     */
    public function reportResult(
        DesktopDevice $device,
        string $leaseToken,
        string $status,
        ?array $result = null,
        ?string $errorCode = null,
    ): ?DesktopJob {
        if (null !== $result) {
            $encoded = json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (false === $encoded || \strlen($encoded) > self::RESULT_MAX_BYTES) {
                throw new ResultTooLargeException(sprintf('Desktop job result exceeds the %d-byte cap.', self::RESULT_MAX_BYTES));
            }
        }

        /** @var DesktopJob|null $updated */
        $updated = $this->em->wrapInTransaction(function () use ($device, $leaseToken, $status, $result, $errorCode): ?DesktopJob {
            $job = $this->jobRepository->findByLeaseToken($leaseToken);

            if (null === $job
                || $job->getOwnerId() !== $device->getOwnerId()
                || DesktopJob::STATUS_LEASED !== $job->getStatus()) {
                return null;
            }

            if (!\in_array($status, [DesktopJob::STATUS_SUCCEEDED, DesktopJob::STATUS_FAILED], true)) {
                return null;
            }

            $job->setStatus($status)
                ->setResult($result)
                ->setErrorCode($errorCode)
                ->setLeaseToken(null)
                ->touch();

            $this->jobRepository->save($job);

            return $job;
        });

        return $updated;
    }

    /**
     * Stop one queued or leased job owned by this user.
     *
     * A queued job is no longer leasable. A leased job loses its lease token,
     * so a later device report is rejected. A skill the computer has already
     * started may still finish on that computer; the server will not record it.
     * A finished job is left unchanged.
     *
     * @return array{job: DesktopJob|null, cancelled: bool}
     */
    public function cancelOwned(int $id, int $ownerId): array
    {
        /** @var array{job: DesktopJob|null, cancelled: bool} $result */
        $result = $this->em->wrapInTransaction(function () use ($id, $ownerId): array {
            $job = $this->jobRepository->findOwnedForUpdate($id, $ownerId);
            if (null === $job || !$this->markCancelled($job)) {
                return ['job' => $job, 'cancelled' => false];
            }

            $this->em->flush();

            return ['job' => $job, 'cancelled' => true];
        });

        return $result;
    }

    /**
     * Cancel every queued or leased job targeted at this device.
     *
     * Jobs that any of the user's computers may still pick up (no device id)
     * are left queued. Same lease rule as {@see cancelOwned()}: work the
     * computer has already started may finish locally, and is not recorded.
     *
     * @return list<DesktopJob>
     */
    public function cancelOpenForDevice(int $ownerId, int $deviceId): array
    {
        /** @var list<DesktopJob> $cancelled */
        $cancelled = $this->em->wrapInTransaction(function () use ($ownerId, $deviceId): array {
            $cancelled = [];
            foreach ($this->jobRepository->findOpenForDevice($ownerId, $deviceId) as $job) {
                if ($job->getDeviceId() !== $deviceId || !$this->markCancelled($job)) {
                    continue;
                }
                $this->em->persist($job);
                $cancelled[] = $job;
            }

            if ([] !== $cancelled) {
                $this->em->flush();
            }

            return $cancelled;
        });

        return $cancelled;
    }

    /**
     * Requeue (or fail) jobs whose lease expired. Called by the reaper.
     *
     * @return array{requeued: int, failed: int, failedJobs: list<DesktopJob>}
     */
    public function requeueExpiredLeases(int $limit = 100): array
    {
        /** @var array{requeued: int, failed: int, failedJobs: list<DesktopJob>} $result */
        $result = $this->em->wrapInTransaction(function () use ($limit): array {
            $now = time();
            $requeued = 0;
            $failed = 0;
            /** @var list<DesktopJob> $failedJobs */
            $failedJobs = [];

            foreach ($this->jobRepository->findExpiredLeases($now, $limit) as $job) {
                if (DesktopJob::STATUS_LEASED !== $job->getStatus() || $job->getLeaseExpires() >= $now) {
                    continue;
                }
                if ($job->getAttempt() < $job->getMaxAttempts()) {
                    $job->setStatus(DesktopJob::STATUS_QUEUED)
                        ->setLeaseToken(null)
                        ->setLeaseExpires(0)
                        ->touch();
                    ++$requeued;
                } else {
                    $this->failTimedOut($job);
                    $failedJobs[] = $job;
                    ++$failed;
                }

                $this->em->persist($job);
            }

            $cutoff = $now - self::QUEUED_TTL_SECONDS;
            foreach ($this->jobRepository->findStaleQueued($cutoff, $limit) as $job) {
                if (!$this->isStillStaleQueued($job, $cutoff)) {
                    continue;
                }
                $this->failTimedOut($job);
                $failedJobs[] = $job;
                ++$failed;
                $this->em->persist($job);
            }

            if ($requeued > 0 || $failed > 0) {
                $this->em->flush();
            }

            return ['requeued' => $requeued, 'failed' => $failed, 'failedJobs' => $failedJobs];
        });

        return $result;
    }

    private function isStillStaleQueued(DesktopJob $job, int $cutoff): bool
    {
        if (DesktopJob::STATUS_QUEUED !== $job->getStatus()) {
            return false;
        }

        $updated = $job->getUpdated();

        return $updated > 0 ? $updated < $cutoff : $job->getCreated() < $cutoff;
    }

    private function failTimedOut(DesktopJob $job): void
    {
        $job->setStatus(DesktopJob::STATUS_FAILED)
            ->setLeaseToken(null)
            ->setErrorCode(DesktopJobContract::ERROR_TIMEOUT)
            ->touch();
    }

    /**
     * Move a queued or leased job to cancelled and drop the lease so the
     * device cannot report a result afterwards.
     */
    private function markCancelled(DesktopJob $job): bool
    {
        if (!\in_array($job->getStatus(), [DesktopJob::STATUS_QUEUED, DesktopJob::STATUS_LEASED], true)) {
            return false;
        }

        $job->setStatus(DesktopJob::STATUS_CANCELLED)
            ->setLeaseToken(null)
            ->setLeaseExpires(0)
            ->touch();

        return true;
    }

    /**
     * A user's most recent jobs, newest first (web "waiting/failed" card).
     *
     * @return list<DesktopJob>
     */
    public function recentForOwner(int $ownerId, int $limit = 50): array
    {
        return $this->jobRepository->findRecentByOwner($ownerId, $limit);
    }

    /**
     * A single owner-scoped job, or null when it does not exist / belongs to
     * another user (the caller turns null into a 404).
     */
    public function findOwnedJob(int $id, int $ownerId): ?DesktopJob
    {
        return $this->jobRepository->findOwnedById($id, $ownerId);
    }

    private static function generateLeaseToken(): string
    {
        return 'lt_'.bin2hex(random_bytes(24));
    }
}
