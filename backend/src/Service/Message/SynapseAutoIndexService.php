<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\User;
use App\Message\SynapseUserReindexMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tiny façade every login flow calls right after issuing tokens, so
 * the user's Synapse Routing topics get refreshed in the background
 * without the auth response having to wait for it.
 *
 * Why a service and not the bus inline at every callsite:
 *   - All four login pathways (email/password, Google, GitHub,
 *     Keycloak) and the impersonation flow have to opt in. Going
 *     through one method makes that easy to grep for and easy to
 *     mock in feature tests.
 *   - Future "needs-reindex" flags (e.g. set by PromptController on
 *     edit) can be checked here before dispatching, without touching
 *     every controller.
 *   - Defensive: never let an auth response fail because the
 *     messenger transport is misconfigured or unreachable. We log
 *     and swallow.
 *
 * Throttling: we keep a per-user cooldown in the app cache so high-
 * traffic logins (e.g. one user reconnecting many times in a short
 * window, or a fleet of OAuth callbacks landing in the same minute)
 * cannot stampede the messenger transport. Cooldown is short enough
 * that prompt edits made between sessions still flow into Qdrant.
 */
final readonly class SynapseAutoIndexService
{
    /**
     * 15 minutes between auto-dispatches per user. Long enough to
     * absorb a refresh storm, short enough that "edit topic, log out,
     * log back in" gets a fresh index within a coffee break. Manual
     * reindex from the admin UI bypasses this cache (uses a direct
     * service call, not the auto-index path).
     */
    private const COOLDOWN_TTL_SECONDS = 15 * 60;

    public function __construct(
        private MessageBusInterface $messageBus,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Schedule a best-effort Synapse topic refresh for the given
     * user. Safe to call from any login pathway; cheap when the
     * user has no custom topics or all topics are already fresh
     * (the handler uses source-hash-based skip).
     *
     * Returns true if a job was actually dispatched (cache miss),
     * false if we suppressed the dispatch because the cooldown for
     * this user is still active. Mostly useful for tests.
     */
    public function scheduleForUser(User $user): bool
    {
        $userId = $user->getId();
        if (null === $userId || $userId <= 0) {
            return false;
        }

        // get() with the same key returns the cached marker until
        // the TTL expires; the closure (and thus dispatch) runs only
        // on a cache miss, giving us per-user rate-limiting for free.
        $dispatched = false;
        try {
            $this->cache->get(
                $this->cooldownKey($userId),
                function (ItemInterface $item) use ($userId, &$dispatched): int {
                    $item->expiresAfter(self::COOLDOWN_TTL_SECONDS);
                    $this->messageBus->dispatch(new SynapseUserReindexMessage($userId));
                    $dispatched = true;

                    return $userId;
                }
            );
        } catch (\Throwable $e) {
            $this->logger->warning('SynapseAutoIndex: dispatch failed (non-blocking)', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $dispatched;
    }

    private function cooldownKey(int $userId): string
    {
        return 'synapse_auto_index.cooldown.user_'.$userId;
    }
}
