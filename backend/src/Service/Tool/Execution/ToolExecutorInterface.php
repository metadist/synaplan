<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Tool\ToolDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.tool.executor')]
interface ToolExecutorInterface
{
    public function supports(ToolDescriptor $tool): bool;

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution;
}
