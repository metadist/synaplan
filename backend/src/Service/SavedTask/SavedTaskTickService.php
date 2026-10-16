<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Repository\ConfigRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Chat\StuckChatReaper;
use App\Service\SavedTask\Schedule\ScheduleParser;
use App\Service\Tool\ApprovalExpiryService;
use Psr\Log\LoggerInterface;

final readonly class SavedTaskTickService
{
    /**
     * The stuck-chat reaper fails the run's chat turn after this long, so the
     * run itself can no longer finish either.
     */
    public const ABANDONED_RUN_SECONDS = StuckChatReaper::MESSAGE_TTL_SECONDS;

    public const ABANDONED_RUN_ERROR = 'This run was interrupted, for example by a server restart, and did not finish. Anything it had already done stays done. Use Run now to start it again.';

    public function __construct(
        private SavedTaskConfig $config,
        private ConfigRepository $configRepository,
        private SavedTaskRepository $tasks,
        private SavedTaskRunRepository $runs,
        private SavedTaskRunner $runner,
        private ScheduleParser $parser,
        private LoggerInterface $logger,
        private ?ApprovalExpiryService $approvalExpiry = null,
    ) {
    }

    public function isGloballyEnabled(): bool
    {
        $global = $this->configRepository->getValue(0, SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED);

        return null !== $global && (filter_var($global, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }

    /**
     * @return array{claimed: int, ran: int, failed: int, skipped: int}
     */
    public function tick(\DateTimeImmutable $nowUtc, int $limit = 20): array
    {
        $claimed = 0;
        $ran = 0;
        $failed = 0;
        $skipped = 0;

        $this->approvalExpiry?->sweep($nowUtc);

        $abandoned = $this->runs->failAbandoned(
            $nowUtc->modify('-'.self::ABANDONED_RUN_SECONDS.' seconds'),
            self::ABANDONED_RUN_ERROR,
        );
        if ($abandoned > 0) {
            $this->logger->warning('SavedTaskTick: marked interrupted runs as failed', ['count' => $abandoned]);
        }

        foreach ($this->tasks->findDueScheduled($limit, $nowUtc) as $task) {
            $expected = $task->getNextRunAt();
            if (null === $expected) {
                continue;
            }

            try {
                $following = $this->parser->nextRunAt($task->getTriggerConfig(), $nowUtc);
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('SavedTaskTick: invalid schedule', [
                    'task_id' => $task->getId(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $backoff = $following;
            $minBackoff = $nowUtc->modify('+5 minutes');
            if ($backoff < $minBackoff) {
                $backoff = $minBackoff;
            }

            if (!$this->tasks->claim($task, $expected, $backoff)) {
                continue;
            }
            ++$claimed;

            $id = $task->getId();
            if (null === $id) {
                continue;
            }

            if (!$this->config->isEnabled($task->getOwnerId())) {
                continue;
            }

            // The claim already moved the task to its next slot, so this
            // occurrence is dropped rather than retried while it overlaps.
            if ($this->runs->hasActiveRunForTask($id)) {
                ++$skipped;
                $this->logger->info('SavedTaskTick: previous run still going, skipped this occurrence', [
                    'task_id' => $id,
                ]);
                continue;
            }

            try {
                // Blank message → the runner uses the task's stored instruction,
                // exactly like a manual "Run now". A synthetic English message
                // here used to leak into the run's chat as the user turn.
                $result = $this->runner->run(
                    $task->getOwnerId(),
                    $id,
                    '',
                    'schedule',
                );
                if ('failed' === $result['run']->getStatus()) {
                    ++$failed;
                } else {
                    ++$ran;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->warning('SavedTaskTick: isolated task failure', [
                    'task_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['claimed' => $claimed, 'ran' => $ran, 'failed' => $failed, 'skipped' => $skipped];
    }
}
