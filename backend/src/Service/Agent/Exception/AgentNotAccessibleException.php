<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentNotAccessibleException extends \RuntimeException
{
    public static function forId(int $agentId): self
    {
        return new self(sprintf('Assistant %d was not found', $agentId));
    }
}
