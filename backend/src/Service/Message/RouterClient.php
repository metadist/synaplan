<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client to the external synaplan-router (SetFit/ONNX) service.
 *
 * Implements a simple circuit breaker: after N consecutive failures the
 * client stops calling the service for a cooldown period, allowing the
 * embedding/LLM fallback tiers to handle classification without the
 * latency penalty of waiting for a timeout on every request.
 */
final class RouterClient
{
    private const DEFAULT_URL = 'http://router:8000';
    private const DEFAULT_TIMEOUT_MS = 100;
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.70;
    private const CIRCUIT_BREAKER_THRESHOLD = 3;
    private const CIRCUIT_BREAKER_RESET_SECONDS = 60;

    private int $consecutiveFailures = 0;
    private ?float $circuitOpenedAt = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigRepository $configRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Classify a message via the external router service.
     *
     * Returns null when the service is unavailable, timed out, or the
     * circuit breaker is open — callers should fall through to the next tier.
     *
     * @return array{use_case: string, confidence: float, is_compound: bool, steps: list<array>, model_version: string, latency_ms: float}|null
     */
    public function classify(string $text, ?string $language = null, ?string $context = null): ?array
    {
        if ($this->isCircuitOpen()) {
            $this->logger->debug('RouterClient: Circuit breaker open, skipping');

            return null;
        }

        $url = $this->getBaseUrl();
        $timeout = $this->getTimeoutSeconds();

        try {
            $response = $this->httpClient->request('POST', $url.'/classify', [
                'json' => array_filter([
                    'text' => $text,
                    'language' => $language,
                    'context' => $context,
                ], static fn ($v) => null !== $v),
                'timeout' => $timeout,
            ]);

            $data = $response->toArray();

            $this->recordSuccess();

            if (!isset($data['use_case'], $data['confidence'])) {
                $this->logger->warning('RouterClient: Invalid response shape', ['data' => $data]);

                return null;
            }

            return [
                'use_case' => (string) $data['use_case'],
                'confidence' => (float) $data['confidence'],
                'is_compound' => (bool) ($data['is_compound'] ?? false),
                'steps' => (array) ($data['steps'] ?? []),
                'model_version' => (string) ($data['model_version'] ?? 'unknown'),
                'latency_ms' => (float) ($data['latency_ms'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->recordFailure();
            $this->logger->info('RouterClient: Request failed', [
                'error' => $e->getMessage(),
                'consecutive_failures' => $this->consecutiveFailures,
            ]);

            return null;
        }
    }

    /**
     * Forward a routing feedback correction to the external service.
     */
    public function submitFeedback(string $text, string $predictedUseCase, string $correctUseCase, ?int $userId = null): bool
    {
        $url = $this->getBaseUrl();

        try {
            $this->httpClient->request('POST', $url.'/feedback', [
                'json' => array_filter([
                    'text' => $text,
                    'predicted_use_case' => $predictedUseCase,
                    'correct_use_case' => $correctUseCase,
                    'user_id' => $userId,
                ], static fn ($v) => null !== $v),
                'timeout' => 2.0,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('RouterClient: Feedback submission failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fetch the list of trained use case labels from the router.
     *
     * @return list<string>
     */
    public function getUseCases(): array
    {
        $url = $this->getBaseUrl();

        try {
            $response = $this->httpClient->request('GET', $url.'/use-cases', [
                'timeout' => 2.0,
            ]);

            $data = $response->toArray();

            return (array) ($data['use_cases'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check health of the external router.
     *
     * @return array{healthy: bool, model_version: ?string, accuracy: ?float}
     */
    public function health(): array
    {
        $url = $this->getBaseUrl();

        try {
            $response = $this->httpClient->request('GET', $url.'/health', [
                'timeout' => 2.0,
            ]);

            $data = $response->toArray();

            return [
                'healthy' => true,
                'model_version' => $data['model_version'] ?? null,
                'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            ];
        } catch (\Throwable) {
            return ['healthy' => false, 'model_version' => null, 'accuracy' => null];
        }
    }

    public function getConfidenceThreshold(): float
    {
        $value = $this->configRepository->getValue(0, 'ROUTER', 'CONFIDENCE_THRESHOLD');

        if (null !== $value && is_numeric($value)) {
            return (float) $value;
        }

        return self::DEFAULT_CONFIDENCE_THRESHOLD;
    }

    private function getBaseUrl(): string
    {
        return $this->configRepository->getValue(0, 'ROUTER', 'SERVICE_URL')
            ?? self::DEFAULT_URL;
    }

    private function getTimeoutSeconds(): float
    {
        $ms = $this->configRepository->getValue(0, 'ROUTER', 'TIMEOUT_MS');

        if (null !== $ms && is_numeric($ms)) {
            return ((float) $ms) / 1000.0;
        }

        return self::DEFAULT_TIMEOUT_MS / 1000.0;
    }

    private function isCircuitOpen(): bool
    {
        if (null === $this->circuitOpenedAt) {
            return false;
        }

        $elapsed = microtime(true) - $this->circuitOpenedAt;

        $resetSeconds = self::CIRCUIT_BREAKER_RESET_SECONDS;
        $configValue = $this->configRepository->getValue(0, 'ROUTER', 'CIRCUIT_BREAKER_RESET_S');
        if (null !== $configValue && is_numeric($configValue)) {
            $resetSeconds = (int) $configValue;
        }

        if ($elapsed >= $resetSeconds) {
            $this->circuitOpenedAt = null;
            $this->consecutiveFailures = 0;

            return false;
        }

        return true;
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->circuitOpenedAt = null;
    }

    private function recordFailure(): void
    {
        ++$this->consecutiveFailures;

        $threshold = self::CIRCUIT_BREAKER_THRESHOLD;
        $configValue = $this->configRepository->getValue(0, 'ROUTER', 'CIRCUIT_BREAKER_THRESHOLD');
        if (null !== $configValue && is_numeric($configValue)) {
            $threshold = (int) $configValue;
        }

        if ($this->consecutiveFailures >= $threshold) {
            $this->circuitOpenedAt = microtime(true);
            $this->logger->warning('RouterClient: Circuit breaker opened', [
                'failures' => $this->consecutiveFailures,
            ]);
        }
    }
}
