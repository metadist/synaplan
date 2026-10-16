<?php

declare(strict_types=1);

namespace App\AI\Exception;

/**
 * Raised when TTS input sanitization leaves nothing to speak
 * (e.g. think-tag-only or code-only replies). Callers treat this as
 * "skip synthesis" rather than a provider failure.
 */
final class NoSpeakableTextException extends \InvalidArgumentException
{
    public function __construct(string $message = 'No speakable text provided', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
