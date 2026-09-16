<?php

declare(strict_types=1);

namespace App\Service\File\Office;

final class DocumentCombineException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        int $statusCode,
    ) {
        parent::__construct($message, $statusCode);
    }
}
