<?php

declare(strict_types=1);

namespace App\Service\Auth;

final class CodeSignupRateLimited extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many invitation-code requests.');
    }
}
