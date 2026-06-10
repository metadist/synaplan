<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\Capability;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Maps a {@see Capability} to the {@see TaskRunner} that executes it.
 *
 * Runners are collected via the `app.multitask.runner` tag (autoconfigured on
 * the interface). The last runner registered for a capability wins; in practice
 * each capability has exactly one runner.
 */
final class RunnerRegistry
{
    /** @var array<string, TaskRunner> capability value => runner */
    private array $byCapability = [];

    /**
     * @param iterable<TaskRunner> $runners
     */
    public function __construct(
        #[AutowireIterator('app.multitask.runner')]
        iterable $runners = [],
    ) {
        foreach ($runners as $runner) {
            foreach ($runner->supportedCapabilities() as $capability) {
                $this->byCapability[$capability->value] = $runner;
            }
        }
    }

    public function has(Capability $capability): bool
    {
        return isset($this->byCapability[$capability->value]);
    }

    public function get(Capability $capability): ?TaskRunner
    {
        return $this->byCapability[$capability->value] ?? null;
    }
}
