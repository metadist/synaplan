<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Admin;

/**
 * A search model change that was refused before anything was stored.
 * `reason` is stable for the UI; the message is for logs.
 */
final class SearchModelChangeException extends \RuntimeException
{
    public const INVALID_MODEL = 'invalid_model';
    public const RUN_IN_PROGRESS = 'run_in_progress';
    public const PROBE_FAILED = 'probe_failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
