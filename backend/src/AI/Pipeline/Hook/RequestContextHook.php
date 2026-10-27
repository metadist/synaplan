<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;
use App\Observability\RequestIdGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stamps the correlation request id, run id and start time on every {@see AiCall}
 * (`03_contracts.md` §1.3, priority 900).
 */
#[AsAiCallHook(priority: 900)]
final readonly class RequestContextHook implements AiCallHookInterface
{
    public function __construct(
        private ?RequestStack $requestStack = null,
        private RequestIdGenerator $requestIdGenerator = new RequestIdGenerator(),
    ) {
    }

    public function before(AiCall $call): AiCall
    {
        $request = $this->requestStack?->getCurrentRequest();
        $requestId = $call->context->requestId;
        if (null === $requestId || '' === $requestId) {
            $attr = $request?->attributes->get(RequestIdGenerator::ATTRIBUTE);
            $requestId = \is_string($attr) && '' !== $attr
                ? $attr
                : $this->requestIdGenerator->sanitize($request?->headers->get(RequestIdGenerator::HEADER));
        }

        $runId = $call->context->runId;
        if (null === $runId || '' === $runId) {
            $optRunId = $call->options->providerOptions['run_id'] ?? null;
            if (\is_string($optRunId) && '' !== $optRunId) {
                $runId = $optRunId;
            }
        }

        return $call->withContext($call->context->withStamp(
            requestId: $requestId,
            runId: $runId,
            startedHrtimeNs: hrtime(true),
        ));
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
    }
}
