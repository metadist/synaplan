<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

use App\Service\Multitask\Execution\NodeResult;

/**
 * A job that is already settled (used for immediate dispatch failures and tests).
 */
final readonly class SettledMediaNodeJob implements MediaNodeJob
{
    public function __construct(private NodeResult $result)
    {
    }

    public function wait(int $timeoutSeconds): NodeResult
    {
        return $this->result;
    }

    public function cancel(): void
    {
        // Already settled — nothing to terminate.
    }
}
