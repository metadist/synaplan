<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the out-of-band digest job across users.
 *
 * The per-user cursor is the highest message id of the contiguous scanned
 * prefix. A pass never scans past the lowest message that only the quiet
 * window excludes (a hole): the messages above a hole wait until it leaves
 * the quiet window. Every scanned message therefore sits at or below the
 * stored cursor, so no later pass sends it again. A successful batch
 * advances the cursor to its last message, including when the model returns
 * no key message, and the cursor never moves backwards.
 *
 * A failed model call does not advance the cursor. Abort-class failures
 * stop the whole run. A batch-caused failure stops that user only; after
 * three failures of the same batch start the batch is skipped. Backfill
 * never moves the cursor.
 */
final readonly class MessageDigestRunner
{
    public const MAX_ATTEMPTS_PER_BATCH = 3;

    public function __construct(
        private MessageDigestService $digestService,
        private MessageDigestConfig $config,
        private MessageRepository $messageRepository,
        private MessageDigestMaintenance $maintenance,
        private UserRepository $userRepository,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Scheduled entry point: digest new messages for every eligible user.
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>}
     */
    public function run(?int $onlyUserId = null, bool $dryRun = false, ?int $maxBatchesPerUser = null): array
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('Message digest job disabled via BCONFIG, skipping run');

            return $this->emptyRunSummary();
        }

        $userIds = null !== $onlyUserId ? [$onlyUserId] : $this->messageRepository->findDistinctUserIds();
        $maxBatches = $maxBatchesPerUser ?? $this->config->getMaxBatchesPerUser();

        return $this->runAcrossUsers($userIds, $maxBatches, $dryRun, null, true, 'run');
    }

    /**
     * Backfill a historical range for one user (or all): starts from message id 0
     * within the `sinceUnix` window and does NOT advance the stored cursor.
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>}
     */
    public function backfill(?int $onlyUserId, int $sinceUnix, bool $dryRun = false, ?int $maxBatchesPerUser = null): array
    {
        $userIds = null !== $onlyUserId ? [$onlyUserId] : $this->messageRepository->findDistinctUserIds();
        $maxBatches = $maxBatchesPerUser ?? $this->config->getMaxBatchesPerUser();

        return $this->runAcrossUsers($userIds, $maxBatches, $dryRun, $sinceUnix, false, 'backfill');
    }

    /**
     * After-turn pass: index other chats immediately (the quiet window applies
     * only to the chat that just received a turn). Cost-capped at two batches.
     *
     * Persists the same contiguous-prefix cursor as {@see run()}, so a message
     * already scanned is not sent again on the next turn. A young live-chat
     * or chat-less row is a hole: the pass stops below it. An abort-class
     * failure returns with `aborted` set and does not move the cursor.
     *
     * @return array{batches: int, created: int, scanned: int, cursor: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{title: string, message_id: int}>}
     */
    public function runForOtherChats(User $user, int $liveChatId, int $maxBatches = 2): array
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('Message digest job disabled via BCONFIG, skipping other-chats pass');

            return $this->emptyUserResult(0);
        }

        return $this->runForUser($user, $maxBatches, liveChatId: $liveChatId);
    }

    /**
     * Digest up to `$maxBatches` batches for one user.
     *
     * `cursor` in the result is the scan position: the last candidate of the
     * last successful or skipped batch.
     *
     * @return array{batches: int, created: int, scanned: int, cursor: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{title: string, message_id: int}>}
     */
    public function runForUser(
        User $user,
        int $maxBatches,
        ?int $sinceUnix = null,
        bool $dryRun = false,
        bool $advanceCursor = true,
        ?int $liveChatId = null,
        bool $persistCursor = true,
    ): array {
        $batchSize = $this->config->getBatchSize();
        $quietCutoff = time() - $this->config->getQuietSeconds();
        $tracksCursor = $advanceCursor && $persistCursor && !$dryRun;

        // Starts at the stored cursor, never at the highest digested message: a
        // digest above a quiet-window hole must not let the scan skip that hole.
        $scanCursor = $advanceCursor ? $this->config->getCursor($user->getId()) : 0;
        $result = $this->emptyUserResult($scanCursor);

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $candidates = $this->messageRepository->findDigestCandidates(
                $user->getId(),
                $scanCursor,
                $quietCutoff,
                $batchSize,
                $sinceUnix,
                $liveChatId,
            );
            if ($advanceCursor) {
                $candidates = $this->belowQuietHole($user, $candidates, $scanCursor, $quietCutoff, $sinceUnix, $liveChatId);
            }

            if ([] === $candidates) {
                break;
            }

            $budget = $this->rateLimitService->checkCostBudget($user);
            if (!$budget['allowed']) {
                $result['skipped_budget'] = 1;
                break;
            }

            $firstId = (int) $candidates[0]->getId();
            $lastId = (int) $candidates[array_key_last($candidates)]->getId();

            if ($tracksCursor && $this->failureCapReached($user->getId(), $scanCursor)) {
                $result['cursor'] = $this->giveUp($user, $firstId, $lastId);
                break;
            }

            // A dry run stores nothing, so later batches learn earlier picks here.
            $batchResult = $this->digestService->digestBatch(
                $user,
                $candidates,
                $dryRun,
                array_column($result['proposals'], 'title'),
            );
            if ($batchResult['failed']) {
                ++$result['failed_batches'];
                $reason = $batchResult['failureReason'];
                if ($this->isAbortReason($reason)) {
                    $result['aborted'] = true;
                    $result['abort_reason'] = $reason;
                    break;
                }
                if ($tracksCursor) {
                    $gaveUpAt = $this->noteBatchFailure($user, $scanCursor, $firstId, $lastId);
                    if (null !== $gaveUpAt) {
                        $result['cursor'] = $gaveUpAt;
                    }
                }
                break;
            }

            ++$result['batches'];
            $result['created'] += $batchResult['created'];
            $result['scanned'] += $batchResult['scanned'];
            if ($dryRun) {
                array_push($result['proposals'], ...$batchResult['proposals']);
            }
            $scanCursor = $lastId;
            $result['cursor'] = $scanCursor;

            if ($tracksCursor) {
                $this->config->advanceCursor($user->getId(), $lastId);
                $this->config->clearCursorFailures($user->getId());
            }
        }

        // Cap enforcement: only after real writes — a dry run must not mutate.
        if ($result['created'] > 0 && !$dryRun) {
            $this->maintenance->pruneOverflow($user->getId());
        }

        if ($result['batches'] > 0 || $result['failed_batches'] > 0 || $result['aborted'] || $result['skipped_budget'] > 0) {
            $this->logger->info('Message digest user pass finished', [
                'user_id' => $user->getId(),
                'batches' => $result['batches'],
                'created' => $result['created'],
                'scanned' => $result['scanned'],
                'cursor' => $result['cursor'],
                'failed_batches' => $result['failed_batches'],
                'aborted' => $result['aborted'],
                'abort_reason' => $result['abort_reason'],
                'skipped_budget' => $result['skipped_budget'],
                'dry_run' => $dryRun,
            ]);
        }

        return $result;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>}
     */
    private function runAcrossUsers(
        array $userIds,
        int $maxBatches,
        bool $dryRun,
        ?int $sinceUnix,
        bool $advanceCursor,
        string $job,
    ): array {
        $summary = $this->emptyRunSummary();

        foreach ($userIds as $index => $userId) {
            $user = $this->userRepository->find($userId);
            if (null === $user || !$user->isMemoriesEnabled()) {
                ++$summary['skipped_users'];
                continue;
            }

            $result = $this->runForUser(
                $user,
                $maxBatches,
                sinceUnix: $sinceUnix,
                dryRun: $dryRun,
                advanceCursor: $advanceCursor,
            );
            $this->absorbUser($summary, $result, $userId);
            if ($result['aborted']) {
                $this->markAborted($summary, $result, \count($userIds) - $index - 1, $job);
                break;
            }
        }

        $this->logger->info('Message digest '.$job.' finished', $summary);

        return $summary;
    }

    /**
     * @param array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>} $summary
     * @param array{batches: int, created: int, scanned: int, failed_batches: int, skipped_budget: int, proposals: list<array{title: string, message_id: int}>}                                                                                     $result
     */
    private function absorbUser(array &$summary, array $result, int $userId): void
    {
        $summary['failed_batches'] += $result['failed_batches'];
        $summary['skipped_budget'] += $result['skipped_budget'];
        foreach ($result['proposals'] as $proposal) {
            $summary['proposals'][] = ['user_id' => $userId] + $proposal;
        }
        if ($result['batches'] > 0) {
            ++$summary['users'];
            $summary['batches'] += $result['batches'];
            $summary['created'] += $result['created'];
            $summary['scanned'] += $result['scanned'];
        }
    }

    /**
     * @param array{aborted: bool, abort_reason: ?string} $summary
     * @param array{abort_reason: ?string}                $result
     */
    private function markAborted(array &$summary, array $result, int $unprocessed, string $job): void
    {
        $summary['aborted'] = true;
        $summary['abort_reason'] = $result['abort_reason'];
        $this->logger->warning(sprintf(
            'Message digest %s aborted (%s); %d users left unprocessed',
            $job,
            $result['abort_reason'] ?? 'unknown',
            $unprocessed,
        ));
    }

    private function isAbortReason(?string $reason): bool
    {
        if (null === $reason || '' === $reason) {
            return false;
        }
        if (MessageDigestService::FAILURE_CIRCUIT_OPEN === $reason
            || MessageDigestService::FAILURE_MODEL_NOT_CONFIGURED === $reason) {
            return true;
        }

        return match (ChatFailureReason::tryFrom($reason)) {
            ChatFailureReason::RateLimited,
            ChatFailureReason::QuotaExceeded,
            ChatFailureReason::AuthFailed,
            ChatFailureReason::UpstreamUnavailable,
            ChatFailureReason::ModelUnavailable,
            ChatFailureReason::Timeout => true,
            default => false,
        };
    }

    private function failureCapReached(int $userId, int $batchStart): bool
    {
        $existing = $this->config->getCursorFailures($userId);

        return null !== $existing
            && $existing['start'] === $batchStart
            && $existing['count'] >= self::MAX_ATTEMPTS_PER_BATCH;
    }

    private function noteBatchFailure(User $user, int $batchStart, int $firstId, int $lastId): ?int
    {
        $existing = $this->config->getCursorFailures($user->getId());
        $count = (null !== $existing && $existing['start'] === $batchStart) ? $existing['count'] + 1 : 1;
        if ($count >= self::MAX_ATTEMPTS_PER_BATCH) {
            return $this->giveUp($user, $firstId, $lastId);
        }

        $this->config->setCursorFailures($user->getId(), $batchStart, $count);

        return null;
    }

    /**
     * Moves the cursor past a batch that failed too often. The batch lies below
     * every hole, so the cursor always moves and the counter can be cleared.
     */
    private function giveUp(User $user, int $firstId, int $lastId): int
    {
        $this->logger->error(sprintf(
            'Message digest batch skipped after %d failures for user %d (messages %d-%d)',
            self::MAX_ATTEMPTS_PER_BATCH,
            $user->getId(),
            $firstId,
            $lastId,
        ));
        $this->config->advanceCursor($user->getId(), $lastId);
        $this->config->clearCursorFailures($user->getId());

        return $lastId;
    }

    /**
     * Candidates below the lowest message that only the quiet window excludes.
     * Scanning past that hole would either strand it below the cursor or send
     * the messages above it again on every later pass.
     *
     * @param Message[] $candidates
     *
     * @return list<Message>
     */
    private function belowQuietHole(
        User $user,
        array $candidates,
        int $afterId,
        int $quietCutoff,
        ?int $sinceUnix,
        ?int $liveChatId,
    ): array {
        if ([] === $candidates) {
            return [];
        }

        $holeId = $this->messageRepository->lowestSkippedDigestCandidateId(
            $user->getId(),
            $afterId,
            $quietCutoff,
            $sinceUnix,
            $liveChatId,
        );
        if (null === $holeId) {
            return array_values($candidates);
        }

        return array_values(array_filter(
            $candidates,
            static fn (Message $message): bool => (int) $message->getId() < $holeId,
        ));
    }

    /**
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>}
     */
    private function emptyRunSummary(): array
    {
        return [
            'users' => 0,
            'skipped_users' => 0,
            'batches' => 0,
            'created' => 0,
            'scanned' => 0,
            'failed_batches' => 0,
            'aborted' => false,
            'abort_reason' => null,
            'skipped_budget' => 0,
            'proposals' => [],
        ];
    }

    /**
     * @return array{batches: int, created: int, scanned: int, cursor: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{title: string, message_id: int}>}
     */
    private function emptyUserResult(int $cursor): array
    {
        return [
            'batches' => 0,
            'created' => 0,
            'scanned' => 0,
            'cursor' => $cursor,
            'failed_batches' => 0,
            'aborted' => false,
            'abort_reason' => null,
            'skipped_budget' => 0,
            'proposals' => [],
        ];
    }
}
