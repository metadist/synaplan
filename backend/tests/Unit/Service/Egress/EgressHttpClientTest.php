<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Egress;

use App\Service\Egress\EgressCall;
use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressDestination;
use App\Service\Egress\EgressHttpClient;
use App\Service\Egress\EgressMetrics;
use App\Service\Egress\EgressMode;
use App\Service\Egress\EgressPolicy;
use App\Service\Egress\EgressRefusedException;
use App\Service\Egress\EgressRule;
use App\Service\Egress\EgressTypedUrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EgressHttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        EgressConfig::setModeOverride(null);
        EgressConfig::setAllowOverride(null);
    }

    public function testOffModeDoesNotChangeTheRequest(): void
    {
        $seen = [];
        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'redirects' => $options['max_redirects'] ?? null];

            return new MockResponse('ok');
        });
        $client = $this->client($inner, EgressMode::Off);

        $response = $client->request('POST', 'https://unknown.example/v1', ['max_redirects' => 3, 'body' => 'x']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://unknown.example/v1', $seen['url']);
        self::assertSame(3, $seen['redirects']);
    }

    public function testEnforceRefusesBeforeASocketOpens(): void
    {
        $called = false;
        $inner = new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('no');
        });
        $client = $this->client($inner, EgressMode::Enforce);

        try {
            $client->request('GET', 'https://unknown.example/secret');
            self::fail('A host that is not allowed must be refused.');
        } catch (EgressRefusedException $e) {
            self::assertSame('unknown.example:443', $e->destination->label());
        }
        self::assertFalse($called);
    }

    public function testReportLetsTheRequestOutAndCountsWouldBlock(): void
    {
        $inner = new MockHttpClient(static fn (): MockResponse => new MockResponse('sent'));
        $metrics = new EgressMetrics();
        $client = $this->client($inner, EgressMode::Report, $metrics);

        $response = $client->request('GET', 'https://unknown.example/path?token=secret');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('sent', $response->getContent());
        self::assertSame(1, $metrics->count('other', 'would_block'));
        self::assertStringContainsString('synaplan_egress_requests_total{destination="other",outcome="would_block"} 1', $metrics->prometheus());
    }

    public function testAnAllowlistedHostIsSentAndATypedUrlUsesTheGuard(): void
    {
        $inner = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok'));
        $metrics = new EgressMetrics();
        $client = $this->client($inner, EgressMode::Enforce, $metrics);

        self::assertSame(200, $client->request('GET', 'https://api.openai.com/v1/models')->getStatusCode());
        self::assertSame(1, $metrics->count('api.openai.com:443', 'allowed'));

        self::assertSame(200, $client->request('GET', 'https://customer.example/hook', EgressCall::typed([]))->getStatusCode());
        self::assertSame(1, $metrics->count('other', 'allowed'));
    }

    public function testARedirectToARefusedHostIsRefusedAndAnAllowedHopFollows(): void
    {
        $urls = [];
        $followed = '';
        $inner = new MockHttpClient(function (string $method, string $url) use (&$urls, &$followed): MockResponse {
            $urls[] = $method.' '.$url;
            $followed = $method.' '.$url;
            if (str_contains($url, 'api.openai.com/start')) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://unknown.example/land']]);
            }
            if (str_contains($url, 'api.openai.com/next')) {
                return new MockResponse('', ['http_code' => 307, 'response_headers' => ['location' => 'https://api.anthropic.com/done']]);
            }

            return new MockResponse('done');
        });
        $client = $this->client($inner, EgressMode::Enforce);

        try {
            $client->request('GET', 'https://api.openai.com/start');
            self::fail('The second hop is not on the list.');
        } catch (EgressRefusedException $e) {
            self::assertSame('unknown.example:443', $e->destination->label());
        }
        self::assertSame(['GET https://api.openai.com/start'], $urls);

        $response = $client->request('POST', 'https://api.openai.com/next', [
            'body' => 'keep',
            'headers' => ['Authorization' => 'Bearer secret'],
        ]);
        self::assertSame('done', $response->getContent());
        self::assertSame('POST https://api.anthropic.com/done', $followed);
        self::assertCount(3, $urls);
    }

    public function testARedirectDropsAuthorizationWhenTheHostChanges(): void
    {
        $headers = [];
        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            if (str_contains($url, 'api.openai.com')) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://api.anthropic.com/next']]);
            }
            $headers = $options['normalized_headers']['authorization'] ?? $options['headers']['Authorization'] ?? null;

            return new MockResponse('ok');
        });
        $client = $this->client($inner, EgressMode::Enforce);
        $client->request('GET', 'https://api.openai.com/from', ['headers' => ['Authorization' => 'Bearer secret']]);

        self::assertNull($headers);
    }

    public function testACallerWhoAskedForNoRedirectsGetsNone(): void
    {
        $count = 0;
        $inner = new MockHttpClient(function () use (&$count): MockResponse {
            ++$count;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://api.anthropic.com/next']]);
        });
        $client = $this->client($inner, EgressMode::Enforce);
        $response = $client->request('GET', 'https://api.openai.com/from', ['max_redirects' => 0]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $count);
    }

    public function testATypedUrlBehindAProxyTunnelsToThePinnedAddress(): void
    {
        $connectTo = null;
        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$connectTo): MockResponse {
            $connectTo = $options['extra']['curl'][\CURLOPT_CONNECT_TO] ?? null;

            return new MockResponse('ok');
        });
        $config = new EgressConfig();
        EgressConfig::setModeOverride(EgressMode::Enforce);
        putenv('EGRESS_PROXY=http://127.0.0.1:9');
        try {
            $client = new EgressHttpClient($inner, new EgressPolicy($config, new AllowTypedGuard(['203.0.113.10'])), $config, new AllowTypedGuard(['203.0.113.10']), new EgressMetrics());
            $client->request('GET', 'https://customer.example/file', EgressCall::typed([]));
        } finally {
            putenv('EGRESS_PROXY');
        }

        self::assertSame(['customer.example:443:203.0.113.10:443'], $connectTo);
    }

    public function testOneWildcardLevelAndAPortRule(): void
    {
        $any = EgressRule::parse('*.example.org');
        $port = EgressRule::parse('api.example.com:443');
        self::assertNotNull($any);
        self::assertNotNull($port);
        self::assertTrue($any->matches(EgressDestination::fromUrl('https://files.example.org/a')));
        self::assertFalse($any->matches(EgressDestination::fromUrl('https://a.files.example.org/a')));
        self::assertFalse($any->matches(EgressDestination::fromUrl('https://example.org/a')));
        self::assertTrue($port->matches(EgressDestination::fromUrl('https://api.example.com/v1')));
        self::assertFalse($port->matches(EgressDestination::fromUrl('http://api.example.com/v1')));
    }

    public function testAllowListParsingAcceptsJsonAndLines(): void
    {
        self::assertSame(['*.example.org', 'api.example.com:443'], EgressConfig::parseAllow('["*.example.org", "api.example.com:443"]'));
        self::assertSame(['*.example.org', 'api.example.com:443'], EgressConfig::parseAllow("*.example.org\napi.example.com:443"));
    }

    private function client(MockHttpClient $inner, EgressMode $mode, ?EgressMetrics $metrics = null): EgressHttpClient
    {
        EgressConfig::setModeOverride($mode);
        $config = new EgressConfig();
        $guard = new AllowTypedGuard();

        return new EgressHttpClient($inner, new EgressPolicy($config, $guard), $config, $guard, $metrics ?? new EgressMetrics());
    }
}

/**
 * @internal
 */
final class AllowTypedGuard implements EgressTypedUrlGuard
{
    /**
     * @param list<string> $pins
     */
    public function __construct(private array $pins = [])
    {
    }

    public function allows(string $url): bool
    {
        return !str_contains($url, 'refused.example');
    }

    public function pins(string $host): array
    {
        return $this->pins;
    }
}
