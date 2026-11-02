<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\User;
use App\Service\Tool\Execution\GateMode;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;

trait ToolExecutionExamples
{
    /**
     * @param array<string, mixed> $meta
     */
    private function descriptor(ToolSource $source, string $name = 'tool', array $meta = [], SideEffect $effect = SideEffect::Read): ToolDescriptor
    {
        return new ToolDescriptor($name, $name, '', [], $effect, $source, 7, null, $meta);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invocation(string $channel, string $name = 'tool', array $arguments = [], ?User $user = null): ToolInvocation
    {
        return new ToolInvocation($name, $arguments, new ToolCaller(7, $channel), GateMode::AsTodayNever, user: $user);
    }
}
