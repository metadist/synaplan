<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;
use App\Service\Usage\AiPipelineMeter;

/**
 * Records AI usage into {@see \App\Service\Usage\TurnUsageLedger} and `BUSELOG`
 * according to `AI.USAGE_HOOK` (`legacy` | `shadow` | `on`, `03_contracts.md`
 * §1.3, §1.5, priority 300, S1 AU11).
 */
#[AsAiCallHook(priority: 300)]
final readonly class UsageHook implements AiCallHookInterface
{
    public function __construct(
        private ?AiPipelineMeter $meter = null,
    ) {
    }

    public function before(AiCall $call): AiCall
    {
        return null !== $this->meter ? $this->meter->prepareTurnContext($call) : $call;
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
        $this->meter?->onCallAfter($call, $result);
    }
}
