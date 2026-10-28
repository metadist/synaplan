<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;
use App\Observability\RequestTimelineContext;
use App\Service\Usage\AiPipelineMeter;

/**
 * Records per-call timing, token counts and cost onto {@see RequestTimelineContext}
 * (`03_contracts.md` §1.3, §9, priority 200, S1 AU13).
 *
 * Runs at priority 200 (after `UsageHook` at 300) so cost and usage are already
 * computed when this hook reads them.
 */
#[AsAiCallHook(priority: 200)]
final readonly class AiCallMetricsHook implements AiCallHookInterface
{
    public function __construct(
        private ?RequestTimelineContext $timeline = null,
        private ?AiPipelineMeter $meter = null,
    ) {
    }

    public function before(AiCall $call): AiCall
    {
        return $call;
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
        if (null === $this->timeline || !$this->timeline->isStarted()) {
            return;
        }

        $usage = $result->usage;
        $tokensIn = null !== $usage ? $usage->promptTokens : 0;
        $tokensOut = null !== $usage ? $usage->completionTokens : 0;
        $quoted = $this->meter?->takeTimelineCost();
        $cost = $quoted
            ?? (null !== $usage && null !== $usage->chargedCost ? $usage->chargedCost : '0.000000');
        $hooksMs = $result->timings->hooksBeforeMs + $result->timings->hooksAfterMs;

        $this->timeline->recordAiCall(
            provider: $call->provider,
            model: $call->model,
            capability: $call->capability->value,
            source: $call->context->source->value,
            ttftMs: $result->timings->ttftMs,
            durationMs: $result->timings->providerMs,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            cost: $cost,
            outcome: $result->outcome->value,
            hooksMs: $hooksMs,
        );
    }
}
