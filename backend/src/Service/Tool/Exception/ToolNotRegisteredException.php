<?php

declare(strict_types=1);

namespace App\Service\Tool\Exception;

final class ToolNotRegisteredException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Tool "%s" is not registered', $name));
    }
}
