<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Hook;

use App\AI\Exception\ProviderException;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallOutcome;
use App\AI\Pipeline\AiCallRefusal;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AsAiCallHook;
use App\Service\Infrastructure\CircuitBreaker;

/**
 * Enforces the provider circuit breaker before the call and records the
 * outcome afterwards (`03_contracts.md` §1.3, priority 700).
 *
 * Replaces `AiFacade::executeWithHealth()`'s circuit-breaker wrapper while
 * keeping identical cache keys, log messages and exception classification.
 */
#[AsAiCallHook(priority: 700)]
final readonly class CircuitBreakerHook implements AiCallHookInterface
{
    public function __construct(
        private CircuitBreaker $circuitBreaker,
    ) {
    }

    public function before(AiCall $call): AiCall|AiCallRefusal
    {
        $serviceName = $call->capability->circuitBreakerService($call->provider);
        if (null === $serviceName) {
            return $call;
        }

        try {
            $this->circuitBreaker->execute(static fn (): bool => true, $serviceName);
        } catch (ProviderException $e) {
            return AiCallRefusal::circuitOpen($call->provider, $e);
        }

        return $call;
    }

    public function after(AiCall $call, AiCallResult $result): void
    {
        $serviceName = $call->capability->circuitBreakerService($call->provider);
        if (null === $serviceName) {
            return;
        }

        if (AiCallOutcome::Failed !== $result->outcome || null === $result->exception) {
            return;
        }

        $exception = $result->exception;
        try {
            $this->circuitBreaker->execute(static function () use ($exception): never {
                throw $exception;
            }, $serviceName);
        } catch (\Throwable) {
        }
    }
}
