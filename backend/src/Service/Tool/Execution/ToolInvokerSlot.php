<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

/**
 * Mutable holder so a readonly caller can receive the shared invoker
 * after construction. The constructor lists are already at the size
 * limit, and a readonly property cannot be assigned in a setter.
 */
final class ToolInvokerSlot
{
    private ?ToolInvoker $invoker = null;

    public function set(ToolInvoker $invoker): void
    {
        $this->invoker = $invoker;
    }

    public function get(): ?ToolInvoker
    {
        return $this->invoker;
    }
}
