<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;

/**
 * Executes a single plan node for a given capability.
 *
 * Implementations are thin adapters over EXISTING handlers/services (the v1
 * design rule: a capability adds no new generation code). Each runner declares
 * the capabilities it handles; the {@see RunnerRegistry} dispatches by capability.
 *
 * A runner MUST NOT throw for an expected failure — return
 * {@see NodeResult::failed()} so the executor can isolate it. (The executor also
 * guards with try/catch as a backstop.)
 */
interface TaskRunner
{
    /**
     * @return list<Capability> capabilities this runner can execute
     */
    public function supportedCapabilities(): array;

    public function run(TaskNode $node, NodeContext $context): NodeResult;
}
