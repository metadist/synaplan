<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

final class SavedTaskDisabledException extends \RuntimeException
{
    public function __construct(string $message = 'Saved Tasks are turned off')
    {
        parent::__construct($message);
    }
}
