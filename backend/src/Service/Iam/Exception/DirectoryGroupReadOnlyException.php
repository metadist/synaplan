<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class DirectoryGroupReadOnlyException extends \RuntimeException
{
    public function __construct(public readonly int $groupId)
    {
        parent::__construct('This comes from the company login and cannot be changed here.');
    }
}
