<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\CloudWatchItem;
use App\Repository\CloudWatchItemRepository;
use App\Repository\CloudWatchRepository;
use App\Service\SavedTask\SavedTaskRunner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A version that has been quiet long enough is finished: a searchable copy
 * is done, and a task watch starts its task. A failure is a terminal state.
 */
final readonly class WatchPromoter
{
    public function __construct(
        private CloudWatchItemRepository $items,
        private CloudWatchRepository $watches,
        private SavedTaskRunner $runner,
        private QuietPeriod $quiet,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function promote(int $now): int
    {
        $done = 0;
        foreach ($this->items->due($now - QuietPeriod::SECONDS) as $item) {
            if ($this->finish($item, $now)) {
                ++$done;
            }
        }
        $this->entityManager->flush();

        return $done;
    }

    private function finish(CloudWatchItem $item, int $now): bool
    {
        $watch = $this->watches->find($item->getWatchId());
        if (!$watch instanceof CloudWatch || CloudWatch::STATE_ACTIVE !== $watch->getState()) {
            return false;
        }
        if (!$this->quiet->due($item->getUpdated(), $now)) {
            return false;
        }
        if ($watch->isSearchable() || null === $watch->getTaskId()) {
            $item->mark(CloudWatchItem::STATE_DONE, null);
            $watch->addCount('done');

            return true;
        }
        try {
            $result = $this->runner->run($watch->getOwnerId(), (int) $watch->getTaskId(), '', 'file_event', [
                'watch' => [
                    'id' => $watch->getId(),
                    'target_kind' => $watch->getTargetKind(),
                    'target_id' => $watch->getTargetId(),
                ],
            ]);
            $item->mark(CloudWatchItem::STATE_DONE, null);
            $item->setRunId($result['run']->getId());
            $watch->setLastRunAt($now);
            $watch->addCount('done');
        } catch (\Throwable) {
            $item->mark(CloudWatchItem::STATE_FAILED, CloudWatchItem::REASON_FAILED_RUN);
            $watch->addCount('failed');
        }

        return true;
    }
}
