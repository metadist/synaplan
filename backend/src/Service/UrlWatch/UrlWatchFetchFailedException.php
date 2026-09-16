<?php

declare(strict_types=1);

namespace App\Service\UrlWatch;

final class UrlWatchFetchFailedException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('could not read the page: '.$reason);
    }
}
