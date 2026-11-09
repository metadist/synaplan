<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate\Exception;

final class AgentTemplateNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Unknown assistant template "%s"', $id));
    }
}
