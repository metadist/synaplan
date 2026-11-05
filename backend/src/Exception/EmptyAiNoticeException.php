<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An owner tried to save a channel AI notice with no sentence.
 * Article 50(1) needs that sentence on every third-party channel.
 */
final class EmptyAiNoticeException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The AI notice cannot be empty. Write at least one sentence.');
    }
}
