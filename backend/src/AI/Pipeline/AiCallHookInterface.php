<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Pre- and post-call hook for {@see AiCallPipeline} (`03_contracts.md` §1.2).
 */
interface AiCallHookInterface
{
    /**
     * Inspect or transform the call before it reaches the provider, or return
     * an {@see AiCallRefusal} to block the call.
     */
    public function before(AiCall $call): AiCall|AiCallRefusal;

    /**
     * Observe the completed, refused, cancelled or failed call.
     */
    public function after(AiCall $call, AiCallResult $result): void;
}
