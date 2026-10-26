<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * A research role has no model that is allowed, healthy and inside the world.
 * The message names the job, so a later screen can show it as it is.
 */
final class RoleUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $role)
    {
        parent::__construct(match ($role) {
            'reader' => 'No model is available for reading sources.',
            'sorter' => 'No model is available for sorting sources.',
            'lead' => 'No model is available for thinking and writing.',
            'writer' => 'No model is available for writing the report.',
            'checker' => 'No model is available for fact-checking.',
            default => 'No model is available for this step.',
        });
    }
}
