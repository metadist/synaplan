<?php

declare(strict_types=1);

namespace App\Service\Agent\Exception;

final class AgentDefinitionException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $path,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
