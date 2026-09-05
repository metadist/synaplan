<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class ShareNotAllowedException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
