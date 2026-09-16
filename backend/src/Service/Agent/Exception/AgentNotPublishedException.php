<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentNotPublishedException extends \RuntimeException
{
    public static function forId(int $agentId): self
    {
        return new self(sprintf('Assistant %d is not published', $agentId));
    }
}
