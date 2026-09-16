<?php

declare(strict_types=1);

namespace App\Service\Stt\Exception;

final class SttSessionNotFoundException extends \RuntimeException
{
    public function __construct(string $sessionId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Transcription session `%s` was not found.', $sessionId), 404, $previous);
    }
}
