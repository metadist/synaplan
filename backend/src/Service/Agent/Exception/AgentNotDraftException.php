<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentNotDraftException extends \RuntimeException
{
    public static function cannotDelete(string $status): self
    {
        return new self(sprintf('Only draft or archived assistants can be deleted (status is "%s")', $status));
    }
}
