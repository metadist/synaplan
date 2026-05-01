<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\User;
use App\Message\SynapseUserReindexMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
 */
final readonly class SynapseAutoIndexService
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Schedule a best-effort Synapse topic refresh for the given
     * user. Safe to call from any login pathway; cheap when the
     * user has no custom topics or all topics are already fresh
     * (the handler uses source-hash-based skip).
     */
    public function scheduleForUser(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId || $userId <= 0) {
            return;
        }

        try {
            $this->messageBus->dispatch(new SynapseUserReindexMessage($userId));
        } catch (\Throwable $e) {
            $this->logger->warning('SynapseAutoIndex: dispatch failed (non-blocking)', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
