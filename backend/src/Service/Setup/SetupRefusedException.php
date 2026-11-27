<?php

declare(strict_types=1);

namespace App\Service\Setup;

final class SetupRefusedException extends \RuntimeException
{
    public function __construct(public readonly SetupRefusal $reason)
    {
        parent::__construct($reason->value);
    }
}
