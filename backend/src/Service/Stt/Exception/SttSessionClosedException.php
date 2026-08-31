<?php

declare(strict_types=1);

namespace App\Service\Stt\Exception;

final class SttSessionClosedException extends \RuntimeException
{
    public function __construct(string $sessionId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Transcription session `%s` is closed.', $sessionId), 409, $previous);
    }
}
