<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class GroupMembershipNotFoundException extends \RuntimeException
{
    public function __construct(public readonly int $groupId)
    {
        parent::__construct('You are not a member of this group.');
    }
}
