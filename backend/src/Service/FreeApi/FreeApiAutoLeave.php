<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use Psr\Log\LoggerInterface;

/**
 * Leaves the Synaplan Demo the moment an admin connects an AI provider of
 * their own (plan D4). Saving the provider must never fail because of the
 * Demo, so this never throws.
 */
final readonly class FreeApiAutoLeave
{
    public function __construct(
        private FreeApiState $state,
        private FreeApiActivator $activator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when the Demo was active and has been left
     */
    public function ownProviderConnected(string $provider): bool
    {
        if (!$this->state->isActive()) {
            return false;
        }

        try {
            $this->activator->leave();
        } catch (\Throwable $e) {
            $this->logger->error('Synaplan Demo auto-leave failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return false;
        }
        $this->logger->info('Synaplan Demo left because an own provider was connected', ['provider' => $provider]);

        return true;
    }
}
