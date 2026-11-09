<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate\Exception;

final class AgentTemplateForbiddenException extends \RuntimeException
{
    public static function everyone(): self
    {
        return new self('Only an administrator can install an assistant for everyone.');
    }
}
