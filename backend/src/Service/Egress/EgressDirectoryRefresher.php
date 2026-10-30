<?php

declare(strict_types=1);

namespace App\Service\Egress;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the allowlist before traffic, not only on an outbound call.
 * Container start runs a console command, and the scheduler keeps
 * refreshing it. The key lasts a day so a quiet minute does not drop it.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'publish', priority: 2048)]
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'publish', priority: 2048)]
final class EgressDirectoryRefresher
{
    public function __construct(
        private readonly EgressConfig $config,
        private readonly EgressPolicy $policy,
        private readonly EgressDirectory $directory,
    ) {
    }

    public function publish(): void
    {
        if (!$this->config->gateway()) {
            return;
        }
        $this->directory->publish($this->policy->publishedRules());
    }
}
