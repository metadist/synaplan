<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Repository\ConfigRepository;
use App\Repository\SavedTaskRepository;
use App\Service\SavedTask\Schedule\ScheduleParser;
use Psr\Log\LoggerInterface;

final readonly class SavedTaskTickService
{
    public function __construct(
        private SavedTaskConfig $config,
        private ConfigRepository $configRepository,
        private SavedTaskRepository $tasks,
        private SavedTaskRunner $runner,
        private ScheduleParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    public function isGloballyEnabled(): bool
    {
        $global = $this->configRepository->getValue(0, SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED);

        return null !== $global && (filter_var($global, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }

    /**
     * @return array{claimed: int, ran: int, failed: int}
     */
    public function tick(\DateTimeImmutable $nowUtc, int $limit = 20): array
    {
        $claimed = 0;
        $ran = 0;
        $failed = 0;

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

            try {
                $result = $this->runner->run(
                    $task->getOwnerId(),
                    $id,
                    'Run scheduled Saved Task: '.$task->getName(),
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

        return ['claimed' => $claimed, 'ran' => $ran, 'failed' => $failed];
    }
}
