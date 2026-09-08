<?php

declare(strict_types=1);

namespace App\Service\UrlWatch;

final class UrlWatchNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('URL watch not found');
    }
}
