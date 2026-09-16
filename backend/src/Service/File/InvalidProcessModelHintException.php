<?php

declare(strict_types=1);

namespace App\Service\File;

final class InvalidProcessModelHintException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid_model_hint',
    ) {
        parent::__construct($message);
    }
}
