<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit Breaker Pattern Implementation.
 *
 * States: CLOSED → OPEN → HALF_OPEN → CLOSED
 *
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Too many failures, all requests fail fast
 * - HALF_OPEN: Testing if service recovered, limited requests
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private int $failureThreshold = 5,
        private int $successThreshold = 2,
        private int $timeout = 60,
        private int $halfOpenMaxCalls = 3,
    ) {
    }

    /**
     * Execute callable with Circuit Breaker protection.
     *
     * @param callable      $callback    The operation to execute
     * @param string        $serviceName Service identifier
     * @param callable|null $fallback    Fallback when circuit is open
     *
     * @throws \Exception
     */
    public function execute(callable $callback, string $serviceName, ?callable $fallback = null): mixed
    {
        $state = $this->getState($serviceName);

        // OPEN: Fail fast
        if (self::STATE_OPEN === $state) {
            if ($this->shouldAttemptReset($serviceName)) {
                $this->setState($serviceName, self::STATE_HALF_OPEN);
                $this->logger->info('Circuit breaker transitioning to HALF_OPEN', [
                    'service' => $serviceName,
                ]);
            } else {
                $this->logger->warning('Circuit breaker is OPEN, failing fast', [
                    'service' => $serviceName,
                ]);

                if ($fallback) {
                    return $fallback();
                }

                // Throw ProviderException instead of RuntimeException
                // Extract provider name from service name (format: ai_provider_xxx)
                $providerName = str_replace('ai_provider_', '', $serviceName);
                throw new \App\AI\Exception\ProviderException('Service temporarily unavailable (circuit breaker is OPEN). Please try again in '.$this->timeout.' seconds.', $providerName);
            }
        }

        // HALF_OPEN: Limit test calls
        if (self::STATE_HALF_OPEN === $state) {
            if ($this->getHalfOpenAttempts($serviceName) >= $this->halfOpenMaxCalls) {
                $this->setState($serviceName, self::STATE_OPEN);
                // Throw ProviderException instead of RuntimeException
                $providerName = str_replace('ai_provider_', '', $serviceName);
                throw new \App\AI\Exception\ProviderException('Service temporarily unavailable (too many test attempts). Please try again later.', $providerName);
            }
            $this->incrementHalfOpenAttempts($serviceName);
        }

        // Execute callback
        try {
            $result = $callback();

            // Success
            $this->recordSuccess($serviceName);

            return $result;
        } catch (\Exception $e) {
            // Failure
            $this->recordFailure($serviceName);

            $this->logger->error('Circuit breaker recorded failure', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
                'state' => $this->getState($serviceName),
            ]);

            throw $e;
        }
    }

    /**
     * Get current circuit state.
     */
    private function getState(string $serviceName): string
    {
        $item = $this->cache->getItem($this->getStateKey($serviceName));

        return $item->isHit() ? $item->get() : self::STATE_CLOSED;
    }

    /**
     * Set circuit state.
     */
    private function setState(string $serviceName, string $state): void
    {
        $item = $this->cache->getItem($this->getStateKey($serviceName));
        $item->set($state);
        $item->expiresAfter(3600); // 1 hour max
        $this->cache->save($item);

        // Reset counters on state change
        if (self::STATE_HALF_OPEN === $state) {
            $this->resetHalfOpenAttempts($serviceName);
        }
        if (self::STATE_CLOSED === $state) {
            $this->resetCounters($serviceName);
        }
    }

    /**
     * Record successful execution.
     */
    private function recordSuccess(string $serviceName): void
    {
        $state = $this->getState($serviceName);

        if (self::STATE_HALF_OPEN === $state) {
            $successCount = $this->incrementSuccessCount($serviceName);

            if ($successCount >= $this->successThreshold) {
                $this->setState($serviceName, self::STATE_CLOSED);
                $this->logger->info('Circuit breaker closed', [
                    'service' => $serviceName,
                ]);
            }
        }

        if (self::STATE_CLOSED === $state) {
            $this->resetFailureCount($serviceName);
        }
    }

    /**
     * Record failed execution.
     */
    private function recordFailure(string $serviceName): void
    {
        $failureCount = $this->incrementFailureCount($serviceName);

        if ($failureCount >= $this->failureThreshold) {
            $this->setState($serviceName, self::STATE_OPEN);
            $this->setOpenTimestamp($serviceName);

            $this->logger->warning('Circuit breaker opened', [
                'service' => $serviceName,
                'failure_count' => $failureCount,
            ]);
        }
    }

    /**
     * Check if should attempt reset from OPEN to HALF_OPEN.
     */
    private function shouldAttemptReset(string $serviceName): bool
    {
        $item = $this->cache->getItem($this->getOpenTimestampKey($serviceName));

        if (!$item->isHit()) {
            return true;
        }

        $openTimestamp = $item->get();

        return (time() - $openTimestamp) >= $this->timeout;
    }

    /**
     * Cache key helpers.
     */
    private function getStateKey(string $serviceName): string
    {
        return "circuit_breaker.state.$serviceName";
    }

    private function getFailureCountKey(string $serviceName): string
    {
        return "circuit_breaker.failures.$serviceName";
    }

    private function getSuccessCountKey(string $serviceName): string
    {
        return "circuit_breaker.successes.$serviceName";
    }

    private function getOpenTimestampKey(string $serviceName): string
    {
        return "circuit_breaker.open_ts.$serviceName";
    }

    private function getHalfOpenAttemptsKey(string $serviceName): string
    {
        return "circuit_breaker.half_open_attempts.$serviceName";
    }

    /**
     * Counter helpers.
     */
    private function incrementFailureCount(string $serviceName): int
    {
        return $this->incrementCounter($this->getFailureCountKey($serviceName));
    }

    private function incrementSuccessCount(string $serviceName): int
    {
        return $this->incrementCounter($this->getSuccessCountKey($serviceName));
    }

    private function incrementHalfOpenAttempts(string $serviceName): int
    {
        return $this->incrementCounter($this->getHalfOpenAttemptsKey($serviceName));
    }

    private function resetFailureCount(string $serviceName): void
    {
        $this->cache->deleteItem($this->getFailureCountKey($serviceName));
    }

    private function resetHalfOpenAttempts(string $serviceName): void
    {
        $this->cache->deleteItem($this->getHalfOpenAttemptsKey($serviceName));
    }

    private function resetCounters(string $serviceName): void
    {
        $this->cache->deleteItem($this->getFailureCountKey($serviceName));
        $this->cache->deleteItem($this->getSuccessCountKey($serviceName));
        $this->cache->deleteItem($this->getHalfOpenAttemptsKey($serviceName));
    }

    private function getHalfOpenAttempts(string $serviceName): int
    {
        $item = $this->cache->getItem($this->getHalfOpenAttemptsKey($serviceName));

        return $item->isHit() ? (int) $item->get() : 0;
    }

    private function setOpenTimestamp(string $serviceName): void
    {
        $item = $this->cache->getItem($this->getOpenTimestampKey($serviceName));
        $item->set(time());
        $item->expiresAfter(3600);
        $this->cache->save($item);
    }

    private function incrementCounter(string $key): int
    {
        $item = $this->cache->getItem($key);
        $count = $item->isHit() ? (int) $item->get() : 0;
        ++$count;

        $item->set($count);
        $item->expiresAfter(300); // 5 minutes
        $this->cache->save($item);

        return $count;
    }

    /**
     * Public API for monitoring.
     */
    public function getCircuitStatus(string $serviceName): array
    {
        $failureItem = $this->cache->getItem($this->getFailureCountKey($serviceName));
        $successItem = $this->cache->getItem($this->getSuccessCountKey($serviceName));

        return [
            'state' => $this->getState($serviceName),
            'failure_count' => $failureItem->isHit() ? (int) $failureItem->get() : 0,
            'success_count' => $successItem->isHit() ? (int) $successItem->get() : 0,
            'threshold' => $this->failureThreshold,
            'timeout' => $this->timeout,
        ];
    }
}
