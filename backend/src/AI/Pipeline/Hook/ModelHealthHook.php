<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Health\ModelHealthRecorder;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallOutcome;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;

/**
 * Reports how each AI call ended to {@see ModelHealthRecorder}
 * (`03_contracts.md` §1.3, priority 600).
 *
 * Replaces `AiFacade::observing()`. Refused calls never reached the provider
 * and do not count as a model success or failure.
 */
#[AsAiCallHook(priority: 600)]
final readonly class ModelHealthHook implements AiCallHookInterface
{
    public function __construct(
        private ModelHealthRecorder $health,
    ) {
    }

    public function before(AiCall $call): AiCall
    {
        return $call;
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
        if (true === ($call->options->providerOptions['skip_model_health'] ?? false)) {
            return;
        }

        $capability = $call->capability->healthCapability();
        if (null === $capability) {
            return;
        }

        if (AiCallOutcome::Ok === $result->outcome) {
            $this->health->recordSuccess($capability, $call->provider, $call->model);

            return;
        }

        if (null !== $result->exception && \in_array($result->outcome, [AiCallOutcome::Failed, AiCallOutcome::Cancelled], true)) {
            $this->health->recordFailure(
                $capability,
                $call->provider,
                $call->model,
                $result->exception,
                $call->context->userId,
            );
        }
    }
}
