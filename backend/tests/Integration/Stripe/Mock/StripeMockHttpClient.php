<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe\Mock;

use PHPUnit\Framework\Assert;
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
 *
 * Every captured request with params is also checked against the `$params`
 * shape the installed SDK declares for that endpoint (see StripeParamShape);
 * call assertParamsMatchSdk() after the test so a parameter Stripe renamed or
 * removed fails here instead of in production.
 */
final class StripeMockHttpClient implements ClientInterface
{
    /**
     * Endpoints the app calls with params, mapped to the SDK method whose
     * `$params` shape describes the request. A request with params to an
     * endpoint missing here is reported, so new call sites get covered too.
     *
     * @var list<array{0: string, 1: string, 2: class-string, 3: string}>
     */
    private const SDK_METHODS = [
        ['post', '#/v1/checkout/sessions$#', \Stripe\Checkout\Session::class, 'create'],
        ['post', '#/v1/billing_portal/sessions$#', \Stripe\BillingPortal\Session::class, 'create'],
        ['post', '#/v1/customers$#', \Stripe\Customer::class, 'create'],
        ['get', '#/v1/subscriptions$#', \Stripe\Subscription::class, 'all'],
        ['post', '#/v1/subscriptions/[^/]+$#', \Stripe\Subscription::class, 'update'],
    ];

    /**
     * @var list<array{method: string, pathContains: string, response: array{0: string, 1: int, 2: array<string, string|list<string>>}, consumed: bool}>
     */
    private array $expectations = [];

    /**
     * @var list<array{method: string, url: string, headers: list<string>, params: array<mixed>, hasFile: bool}>
     */
    private array $captured = [];

    /** @var list<string> */
    private array $paramViolations = [];

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

    public function assertParamsMatchSdk(): void
    {
        Assert::assertSame([], $this->paramViolations, 'Outbound Stripe params do not match the $params shape of the installed stripe-php SDK. A renamed or removed Stripe API parameter is the usual cause; check the SDK changelog and the new key.');
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
        $this->checkParamsAgainstSdk($methodLower, $absUrl, $params);

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

    /**
     * @param array<mixed> $params
     */
    private function checkParamsAgainstSdk(string $method, string $absUrl, array $params): void
    {
        if ([] === $params) {
            return;
        }

        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        foreach (self::SDK_METHODS as [$routeMethod, $pattern, $class, $sdkMethod]) {
            if ($routeMethod !== $method || 1 !== preg_match($pattern, $path)) {
                continue;
            }
            foreach (StripeParamShape::unknownKeys($class, $sdkMethod, $params) as $key) {
                $this->paramViolations[] = sprintf('%s %s: "%s" is not a parameter of %s::%s()', strtoupper($method), $path, $key, $class, $sdkMethod);
            }

            return;
        }

        $this->paramViolations[] = sprintf('%s %s sends params but has no SDK method in StripeMockHttpClient::SDK_METHODS to check them against', strtoupper($method), $path);
    }
}
