<?php

declare(strict_types=1);

namespace App\Service\Summary;

/**
 * The model stopped because it ran out of room, so the text is not the whole
 * answer. A summary may still be usable; a translation may not.
 */
final class IncompleteGenerationException extends \RuntimeException
{
    public static function translationCutOff(): self
    {
        return new self('The translation was cut off because the document is too long for one answer. Try a shorter document.');
    }
}
