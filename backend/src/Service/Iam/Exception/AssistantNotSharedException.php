<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

final class AssistantNotSharedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('iam.assistantNotShared');
    }
}
