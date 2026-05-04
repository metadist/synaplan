<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe\Mock;

use Stripe\HttpClient\ClientInterface;

/**
 * Recording / replaying mock for the Stripe SDK's HTTP client.
 *
 * Tests register expected outbound calls (HTTP method + path pattern) and the
 * canned response that should come back. Every request the SDK makes is also
 * recorded so the test can assert which Stripe API endpoints the controller
 * actually hit and with what parameters.
 *
 * Wire it in by calling \Stripe\ApiRequestor::setHttpClient($mock) in the
 * test's setUp() and resetting to null (= default cURL client) in tearDown().
 *
 * Path matching is substring-based on the absolute Stripe URL — e.g.
 *   $mock->expect('POST', 'subscriptions/sub_123', ['id' => 'sub_123', ...]);
 * matches POST https://api.stripe.com/v1/subscriptions/sub_123. We don't try
 * to be a full URL router because the Stripe SDK already normalises endpoints
 * and we want test failures to point at the wrong call, not at the matcher.
 */
final class StripeMockHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, pathContains: string, response: array{0: string, 1: int, 2: array<string, string|list<string>>}, consumed: bool}>
     */
    private array $expectations = [];

    /**
     * @var list<array{method: string, url: string, headers: list<string>, params: array<mixed>, hasFile: bool}>
     */
    private array $captured = [];

    /**
     * Register an expected Stripe API call. Body is JSON-encoded automatically.
     *
     * @param array<mixed> $body Decoded response body; will be JSON-encoded for the SDK
     */
    public function expect(string $method, string $pathContains, array $body, int $status = 200): self
    {
        $this->expectations[] = [
            'method' => strtolower($method),
            'pathContains' => $pathContains,
            'response' => [json_encode($body, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json']],
            'consumed' => false,
        ];

        return $this;
    }

    /**
     * Register a canned response that matches every call. Useful when a test
     * doesn't care about the specific endpoint but the controller still makes
     * Stripe calls (e.g. cancelOtherSubscriptions calling Subscription::all
     * with empty result during routine subscription.created webhook handling).
     *
     * @param array<mixed> $body
     */
    public function expectAny(array $body, int $status = 200): self
    {
        return $this->expect('GET', '', $body, $status)
            ->expect('POST', '', $body, $status)
            ->expect('DELETE', '', $body, $status);
    }

    /**
     * @return list<array{method: string, url: string, headers: list<string>, params: array<mixed>, hasFile: bool}>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    /**
     * Number of times an endpoint matching $pathContains was hit.
     */
    public function countCalls(string $method, string $pathContains): int
    {
        $count = 0;
        $methodLower = strtolower($method);
        foreach ($this->captured as $call) {
            if ($call['method'] === $methodLower && str_contains($call['url'], $pathContains)) {
                ++$count;
            }
        }

        return $count;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $methodLower = strtolower($method);
        $this->captured[] = [
            'method' => $methodLower,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
            'hasFile' => (bool) $hasFile,
        ];

        foreach ($this->expectations as $i => $exp) {
            if ($exp['consumed']) {
                continue;
            }
            if ($exp['method'] !== $methodLower) {
                continue;
            }
            if ('' !== $exp['pathContains'] && !str_contains($absUrl, $exp['pathContains'])) {
                continue;
            }
            $this->expectations[$i]['consumed'] = true;

            return $exp['response'];
        }

        throw new \RuntimeException(sprintf('Unexpected Stripe API call: %s %s. Configure StripeMockHttpClient::expect() for it. Captured so far: %d call(s).', strtoupper($method), $absUrl, count($this->captured)));
    }
}
