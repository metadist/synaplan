<?php

declare(strict_types=1);

namespace App\AI\Exception;

final class ModelNotConfiguredException extends \RuntimeException
{
    public function __construct(string $message = 'No AI model configured. Please configure a default CHAT model in settings.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
