<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentNothingChangedException extends \RuntimeException
{
    public static function identical(): self
    {
        return new self('nothing_changed');
    }
}
