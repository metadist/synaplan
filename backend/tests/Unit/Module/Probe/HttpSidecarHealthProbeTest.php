<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Probe;

use App\Module\Probe\HttpSidecarHealthProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpSidecarHealthProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statusCodes(): iterable
    {
        yield '200 up' => [200, true];
        yield '204 up' => [204, true];
        yield '404 counts as reachable' => [404, true];
        yield '401 is down (auth misconfigured)' => [401, false];
        yield '403 is down (auth misconfigured)' => [403, false];
        yield '500 down' => [500, false];
        yield '503 down' => [503, false];
    }

    #[DataProvider('statusCodes')]
    public function testIsReachableFollowsTheLegacyStatusPageSemantics(int $code, bool $expected): void
    {
        $probe = new HttpSidecarHealthProbe(new MockHttpClient(new MockResponse('', ['http_code' => $code])));

        $this->assertSame($expected, $probe->isReachable('http://sidecar/health'));
    }

    public function testTransportFailureIsDown(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        $this->assertFalse((new HttpSidecarHealthProbe($client))->isReachable('http://sidecar/health'));
        $this->assertNull((new HttpSidecarHealthProbe($client))->fetchText('http://sidecar/version'));
    }

    public function testBasicAuthIsSentOnlyWhenAUserIsGiven(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['normalized_headers']['authorization'] ?? null;

            return new MockResponse('ok');
        });
        $probe = new HttpSidecarHealthProbe($client);

        $probe->isReachable('http://sidecar/a');
        $probe->isReachable('http://sidecar/b', 'user', 'pass');
        $probe->isReachable('http://sidecar/c', '', 'ignored');

        $this->assertNull($seen[0]);
        $this->assertSame(['Authorization: Basic '.base64_encode('user:pass')], $seen[1]);
        $this->assertNull($seen[2]);
    }

    public function testProbesTheSameUrlOnceWithinARequestAndAgainAfterReset(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('v1');
        });
        $probe = new HttpSidecarHealthProbe($client);

        $this->assertTrue($probe->isReachable('http://tika/tika'));
        $this->assertTrue($probe->isReachable('http://tika/tika'));
        $this->assertSame('v1', $probe->fetchText('http://tika/version'));
        $this->assertSame('v1', $probe->fetchText('http://tika/version'));
        $this->assertSame(2, $calls, 'one request per distinct URL');

        // A different user on the same URL is a different probe.
        $this->assertTrue($probe->isReachable('http://tika/tika', 'user', 'pass'));
        $this->assertSame(3, $calls);

        $probe->reset();
        $this->assertTrue($probe->isReachable('http://tika/tika'));
        $this->assertSame(4, $calls, 'the memo is cleared between requests');
    }

    public function testAFailedProbeIsMemoisedToo(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('resolving timed out');
        });
        $probe = new HttpSidecarHealthProbe($client);

        $this->assertFalse($probe->isReachable('http://docling/health'));
        $this->assertFalse($probe->isReachable('http://docling/health'));
        $this->assertNull($probe->fetchText('http://docling/health'));
        $this->assertNull($probe->fetchText('http://docling/health'));

        $this->assertSame(2, $calls, 'one attempt per URL and method');
    }

    public function testFetchTextReturnsBodyOnlyFor2xx(): void
    {
        $ok = new HttpSidecarHealthProbe(new MockHttpClient(new MockResponse("Apache Tika 2.9.2\n")));
        $this->assertSame("Apache Tika 2.9.2\n", $ok->fetchText('http://tika/version'));

        $notFound = new HttpSidecarHealthProbe(new MockHttpClient(new MockResponse('nope', ['http_code' => 404])));
        $this->assertNull($notFound->fetchText('http://tika/version'));
    }
}
