<?php

declare(strict_types=1);

namespace App\Service\Security;

final class TooManyRedirectsException extends \RuntimeException
{
    public function __construct(int $limit)
    {
        parent::__construct(sprintf('Stopped after %d redirects', $limit));
    }
}
