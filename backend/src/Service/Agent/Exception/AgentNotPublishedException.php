<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentNotPublishedException extends \RuntimeException
{
    public static function forId(int $agentId): self
    {
        return new self(sprintf('Agent %d is not published', $agentId));
    }
}
