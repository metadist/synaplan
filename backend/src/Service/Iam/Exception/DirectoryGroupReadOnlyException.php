<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class DirectoryGroupReadOnlyException extends \RuntimeException
{
    public function __construct(int $groupId)
    {
        parent::__construct(sprintf('Directory group %d cannot be deleted or renamed from the People page.', $groupId));
    }
}
