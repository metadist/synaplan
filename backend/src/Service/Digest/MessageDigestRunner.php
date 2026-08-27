<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the out-of-band digest job across users.
 *
 * Cursor semantics: the per-user BCONFIG cursor records the highest message
 * id a run has SCANNED. It advances even over batches that yielded no key
 * message, so a chatty-but-unimportant stretch of history is billed exactly
 * once. Backfill runs never move the cursor (idempotency comes from the
 * one-digest-per-message unique key instead), so a backfill can safely
 * revisit ranges the daily job already passed.
 */
final readonly class MessageDigestRunner
{
    public function __construct(
        private MessageDigestService $digestService,
        private MessageDigestConfig $config,
        private MessageRepository $messageRepository,
        private MessageDigestRepository $digestRepository,
        private MessageDigestMaintenance $maintenance,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Scheduled entry point: digest new messages for every eligible user.
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int}
     */
    public function run(?int $onlyUserId = null, bool $dryRun = false, ?int $maxBatchesPerUser = null): array
    {
        $summary = ['users' => 0, 'skipped_users' => 0, 'batches' => 0, 'created' => 0, 'scanned' => 0];

        if (!$this->config->isEnabled()) {
            $this->logger->info('Message digest job disabled via BCONFIG, skipping run');

            return $summary;
        }

        $userIds = null !== $onlyUserId ? [$onlyUserId] : $this->messageRepository->findDistinctUserIds();
        $maxBatches = $maxBatchesPerUser ?? $this->config->getMaxBatchesPerUser();

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            if (null === $user || !$user->isMemoriesEnabled()) {
                ++$summary['skipped_users'];
                continue;
            }

            $result = $this->runForUser($user, $maxBatches, dryRun: $dryRun);

            if ($result['batches'] > 0) {
                ++$summary['users'];
                $summary['batches'] += $result['batches'];
                $summary['created'] += $result['created'];
                $summary['scanned'] += $result['scanned'];
            }
        }

        $this->logger->info('Message digest run finished', $summary);

        return $summary;
    }

    /**
     * Backfill a historical range for one user (or all): starts from message id 0
     * within the `sinceUnix` window and does NOT advance the stored cursor.
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int}
     */
    public function backfill(?int $onlyUserId, int $sinceUnix, bool $dryRun = false, ?int $maxBatchesPerUser = null): array
    {
        $summary = ['users' => 0, 'skipped_users' => 0, 'batches' => 0, 'created' => 0, 'scanned' => 0];

        $userIds = null !== $onlyUserId ? [$onlyUserId] : $this->messageRepository->findDistinctUserIds();
        $maxBatches = $maxBatchesPerUser ?? $this->config->getMaxBatchesPerUser();

        foreach ($userIds as $userId) {
            $user = $this->userRepository->find($userId);
            if (null === $user || !$user->isMemoriesEnabled()) {
                ++$summary['skipped_users'];
                continue;
            }

            $result = $this->runForUser($user, $maxBatches, sinceUnix: $sinceUnix, dryRun: $dryRun, advanceCursor: false);

            if ($result['batches'] > 0) {
                ++$summary['users'];
                $summary['batches'] += $result['batches'];
                $summary['created'] += $result['created'];
                $summary['scanned'] += $result['scanned'];
            }
        }

        $this->logger->info('Message digest backfill finished', $summary);

        return $summary;
    }

    /**
     * Digest up to `$maxBatches` batches for one user.
     *
     * @return array{batches: int, created: int, scanned: int, cursor: int}
     */
    public function runForUser(
        User $user,
        int $maxBatches,
        ?int $sinceUnix = null,
        bool $dryRun = false,
        bool $advanceCursor = true,
    ): array {
        $batchSize = $this->config->getBatchSize();
        $quietCutoff = time() - $this->config->getQuietSeconds();

        // The cursor never trails the digest table: if rows exist above the
        // stored cursor (e.g. from a backfill), scanning resumes after them.
        $cursor = $advanceCursor
            ? max($this->config->getCursor($user->getId()), $this->digestRepository->maxMessageIdForUser($user->getId()))
            : 0;

        $result = ['batches' => 0, 'created' => 0, 'scanned' => 0, 'cursor' => $cursor];

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $candidates = $this->messageRepository->findDigestCandidates(
                $user->getId(),
                $result['cursor'],
                $quietCutoff,
                $batchSize,
                $sinceUnix,
            );

            if ([] === $candidates) {
                break;
            }

            $batchResult = $this->digestService->digestBatch($user, $candidates, $dryRun);

            $lastMessage = $candidates[array_key_last($candidates)];
            $result['cursor'] = (int) $lastMessage->getId();
            ++$result['batches'];
            $result['created'] += $batchResult['created'];
            $result['scanned'] += $batchResult['scanned'];

            if ($advanceCursor && !$dryRun) {
                $this->config->setCursor($user->getId(), $result['cursor']);
            }
        }

        // Cap enforcement: only after real writes — a dry run must not mutate.
        if ($result['created'] > 0 && !$dryRun) {
            $this->maintenance->pruneOverflow($user->getId());
        }

        if ($result['batches'] > 0) {
            $this->logger->info('Message digest user pass finished', [
                'user_id' => $user->getId(),
                'batches' => $result['batches'],
                'created' => $result['created'],
                'scanned' => $result['scanned'],
                'cursor' => $result['cursor'],
                'dry_run' => $dryRun,
            ]);
        }

        return $result;
    }
}
