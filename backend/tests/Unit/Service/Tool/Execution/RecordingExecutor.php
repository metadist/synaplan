<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Service\Tool\Execution\ToolExecution;
use App\Service\Tool\Execution\ToolExecutorInterface;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\ToolDescriptor;

final class RecordingExecutor implements ToolExecutorInterface
{
    public int $calls = 0;

    public function supports(ToolDescriptor $tool): bool
    {
        return true;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        ++$this->calls;

        return new ToolExecution('ok', false, 'mcp', 'read');
    }
}
