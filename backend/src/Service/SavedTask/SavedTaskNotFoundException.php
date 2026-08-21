<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

final class SavedTaskNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Saved Task was not found');
    }
}
