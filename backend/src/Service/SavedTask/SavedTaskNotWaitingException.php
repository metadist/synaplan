<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

final class SavedTaskNotWaitingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This run is not waiting for approval');
    }
}
