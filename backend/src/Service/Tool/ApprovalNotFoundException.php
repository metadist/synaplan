<?php

declare(strict_types=1);

namespace App\Service\Tool;

final class ApprovalNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct(sprintf('Approval %d was not found', $id));
    }
}
