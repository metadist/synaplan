<?php

declare(strict_types=1);

namespace App\Service\Exception;

final class NoModelAvailableException extends \RuntimeException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct($detail, 422, $previous);
    }
}
