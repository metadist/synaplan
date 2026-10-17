<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * The tools:enhance prompt is not configured, so a text cannot be enhanced.
 */
final class EnhancePromptMissingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Enhancement prompt not found');
    }
}
