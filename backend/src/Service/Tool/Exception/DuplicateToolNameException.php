<?php

declare(strict_types=1);

namespace App\Service\Tool\Exception;

final class DuplicateToolNameException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Duplicate tool name "%s" in the tool registry', $name));
    }
}
