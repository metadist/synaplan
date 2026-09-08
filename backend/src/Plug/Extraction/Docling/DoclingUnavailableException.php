<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Docling;

final class DoclingUnavailableException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
