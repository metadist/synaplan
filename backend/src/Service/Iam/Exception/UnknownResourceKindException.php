<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class UnknownResourceKindException extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Unknown IAM resource kind "%s".', $key));
    }
}
