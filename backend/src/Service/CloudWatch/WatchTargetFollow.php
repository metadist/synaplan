<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\CloudWatchRepository;
use App\Repository\ExternalIdentityRepository;

/**
 * A deleted cloud file or folder stops the watch that pointed at it. Restoring
 * it within 7 days starts the watch again.
 */
final readonly class WatchTargetFollow
{
    public const GRACE_SECONDS = 604800;

    public function __construct(
        private CloudWatchRepository $watches,
        private ExternalIdentityRepository $identities,
        private WatchPublisher $publisher,
    ) {
    }

    public function apply(PlatformInstance $instance, string $event, string $targetId, int $now): bool
    {
        if (!in_array($event, ['deleted', 'restored'], true)) {
            return false;
        }
        $changed = false;
        foreach ($this->watches->targets($instance->getInstanceId(), $targetId) as $watch) {
            $did = $this->follow($watch, $event, $now);
            $identity = null !== $watch->getIdentityId() ? $this->identities->find($watch->getIdentityId()) : null;
            if ($did && $identity instanceof ExternalIdentity) {
                $this->publisher->changed($watch, $identity);
            }
            $changed = $changed || $did;
        }

        return $changed;
    }

    private function follow(CloudWatch $watch, string $event, int $now): bool
    {
        if ('deleted' === $event && CloudWatch::STATE_STOPPED !== $watch->getState()) {
            $watch->mark(CloudWatch::STATE_ATTENTION, CloudWatch::REASON_TARGET_GONE);
            $watch->setLastEventAt($now);

            return true;
        }
        if ('restored' === $event && CloudWatch::REASON_TARGET_GONE === $watch->getReason()) {
            $goneAt = $watch->getLastEventAt() ?? 0;
            if ($now - $goneAt <= self::GRACE_SECONDS) {
                $watch->mark(CloudWatch::STATE_ACTIVE, null);

                return true;
            }
        }

        return false;
    }
}
