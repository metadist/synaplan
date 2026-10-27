<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Repository\CloudWatchRepository;
use App\Service\PlatformLink\LifecycleEffect;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A cloud person who is disabled, turned off or deleted takes their watches
 * with them. Turning the link back on resumes only the watches that pause caused.
 */
final readonly class WatchLifecycleEffect implements LifecycleEffect
{
    public function __construct(
        private CloudWatchRepository $watches,
        private EntityManagerInterface $entityManager,
        private WatchPublisher $publisher,
        private WatchTasks $tasks,
    ) {
    }

    public function onDeleted(ExternalIdentity $identity): void
    {
        $this->each($identity, function (CloudWatch $watch): void {
            if (CloudWatch::STATE_STOPPED === $watch->getState()) {
                return;
            }
            $watch->mark(CloudWatch::STATE_STOPPED, CloudWatch::REASON_IDENTITY_DELETED);
            $this->tasks->release($watch);
        });
    }

    public function onPaused(ExternalIdentity $identity): void
    {
        $reason = $identity->cloudDisabled()
            ? CloudWatch::REASON_PLATFORM_USER_DISABLED
            : CloudWatch::REASON_IDENTITY_OFF;
        $this->each($identity, function (CloudWatch $watch) use ($reason): void {
            if (CloudWatch::STATE_ACTIVE !== $watch->getState()) {
                return;
            }
            $watch->mark(CloudWatch::STATE_PAUSED, $reason);
        });
    }

    public function onResumed(ExternalIdentity $identity): void
    {
        $this->each($identity, function (CloudWatch $watch) use ($identity): void {
            if ($identity->isOff() || CloudWatch::STATE_PAUSED !== $watch->getState()) {
                return;
            }
            if (in_array($watch->getReason(), [CloudWatch::REASON_IDENTITY_OFF, CloudWatch::REASON_PLATFORM_USER_DISABLED], true)) {
                $watch->mark(CloudWatch::STATE_ACTIVE, null);
            }
        });
    }

    /** @param callable(CloudWatch): void $change */
    private function each(ExternalIdentity $identity, callable $change): void
    {
        $id = $identity->getId();
        if (null === $id) {
            return;
        }
        $rows = $this->watches->forIdentity($id);
        $instanceId = $identity->getInstanceId();
        foreach ($rows as $watch) {
            $before = $watch->getState().'|'.$watch->getReason();
            $change($watch);
            if ($before === $watch->getState().'|'.$watch->getReason()) {
                continue;
            }
            $this->watches->stamp($watch, $instanceId);
            $this->publisher->changed($watch, $identity);
        }
        $this->entityManager->flush();
    }
}
