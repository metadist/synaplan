<?php

declare(strict_types=1);

namespace App\Service\Stt\Exception;

final class SttModelNotFoundException extends \RuntimeException
{
    public function __construct(?string $model, ?\Throwable $previous = null)
    {
        $message = null !== $model && '' !== $model
            ? sprintf('The speech-to-text model `%s` does not exist or is not available.', $model)
            : 'No speech-to-text model specified and no default SOUND2TEXT model is configured.';

        parent::__construct($message, 404, $previous);
    }
}
