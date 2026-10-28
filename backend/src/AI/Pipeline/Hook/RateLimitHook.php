<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallRefusal;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;
use App\Service\Usage\AiPipelineMeter;

/**
 * Enforces caller rate limits before billable AI calls when `AI.USAGE_HOOK=on`
 * (`03_contracts.md` §1.3, priority 800, S1 AU11).
 */
#[AsAiCallHook(priority: 800)]
final readonly class RateLimitHook implements AiCallHookInterface
{
    public function __construct(
        private ?AiPipelineMeter $meter = null,
    ) {
    }

    public function before(AiCall $call): AiCall|AiCallRefusal
    {
        $refusal = $this->meter?->checkRateLimit($call);

        return $refusal ?? $call;
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
    }
}
