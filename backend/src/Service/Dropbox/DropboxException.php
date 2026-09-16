<?php

declare(strict_types=1);

namespace App\Service\Dropbox;

final class DropboxException extends \RuntimeException
{
    /** Dropbox `error_summary` tag, e.g. "path/insufficient_space" — '' when unknown. */
    public function __construct(
        string $message,
        public readonly string $errorSummary = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
