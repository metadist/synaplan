<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Update;

use App\Service\Update\ReleaseManifest;
use App\Service\Update\UpdateManifestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The manifest is a file on someone else's server: every shape it can arrive in
 * — including "not at all" — must degrade to "no usable manifest" instead of an
 * exception in the caller.
 */
final class UpdateManifestClientTest extends TestCase
{
    private const URL = 'https://example.com/versions.json';

    public function testParsesAWellFormedManifest(): void
    {
        $client = $this->clientFor(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => [
                'version' => '4.0.13',
                'releasedAt' => '2026-08-10T09:00:00Z',
                'notesUrl' => 'https://github.com/metadist/synaplan/releases/tag/v4.0.13',
                'severity' => 'security',
            ],
            'yanked' => ['4.0.11'],
        ])));

        $manifest = $client->fetch(self::URL);

        self::assertInstanceOf(ReleaseManifest::class, $manifest);
        self::assertSame('4.0.13', $manifest->version);
        self::assertSame('2026-08-10T09:00:00Z', $manifest->releasedAt);
        self::assertSame('https://github.com/metadist/synaplan/releases/tag/v4.0.13', $manifest->notesUrl);
        self::assertSame(ReleaseManifest::SEVERITY_SECURITY, $manifest->severity);
        self::assertSame(['4.0.11'], $manifest->yankedVersions);
        self::assertTrue($manifest->isYanked('4.0.11'));
        self::assertFalse($manifest->isYanked('4.0.13'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableBodyProvider(): iterable
    {
        yield 'empty body' => [''];
        yield 'whitespace only' => ["  \n"];
        yield 'malformed json' => ['{"schema": 1, "stable":'];
        yield 'json scalar' => ['"just a string"'];
        yield 'json list' => ['[1, 2, 3]'];
        yield 'newer schema' => ['{"schema": 2, "stable": {"version": "9.0.0"}}'];
        yield 'schema as string' => ['{"schema": "1", "stable": {"version": "4.0.13"}}'];
        yield 'schema missing' => ['{"stable": {"version": "4.0.13"}}'];
        yield 'stable missing' => ['{"schema": 1}'];
        yield 'stable not an object' => ['{"schema": 1, "stable": "4.0.13"}'];
        yield 'version missing' => ['{"schema": 1, "stable": {"severity": "normal"}}'];
        yield 'version empty' => ['{"schema": 1, "stable": {"version": "   "}}'];
        yield 'version not a string' => ['{"schema": 1, "stable": {"version": 4}}'];
        yield 'version malformed' => ['{"schema": 1, "stable": {"version": "not-a-version"}}'];
        yield 'version with injection' => ['{"schema": 1, "stable": {"version": "4.0.13; rm -rf /"}}'];
    }

    #[DataProvider('unusableBodyProvider')]
    public function testUnusableBodyYieldsNoManifest(string $body): void
    {
        self::assertNull($this->clientFor(new MockResponse($body))->fetch(self::URL));
    }

    public function testHttpErrorStatusYieldsNoManifest(): void
    {
        $response = new MockResponse('Not Found', ['http_code' => 404]);

        self::assertNull($this->clientFor($response)->fetch(self::URL));
    }

    public function testTransportFailureYieldsNoManifest(): void
    {
        $client = $this->clientFor(new MockResponse('', [
            'error' => 'Could not resolve host: example.com',
        ]));

        self::assertNull($client->fetch(self::URL));
    }

    public function testOversizedResponseYieldsNoManifest(): void
    {
        $client = $this->clientFor(new MockResponse(str_repeat('a', 70000)));

        self::assertNull($client->fetch(self::URL));
    }

    public function testUnknownSeverityFallsBackToNormal(): void
    {
        $client = $this->clientFor(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13', 'severity' => 'catastrophic'],
        ])));

        $manifest = $client->fetch(self::URL);

        self::assertInstanceOf(ReleaseManifest::class, $manifest);
        self::assertSame(ReleaseManifest::SEVERITY_NORMAL, $manifest->severity);
    }

    public function testUnusableOptionalFieldsBecomeNull(): void
    {
        $client = $this->clientFor(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => [
                'version' => '4.1.0-rc.1',
                'notesUrl' => 'javascript:alert(1)',
                'releasedAt' => 'the day before yesterday',
            ],
            'yanked' => ['4.0.11', 'nonsense', 42, '4.0.11'],
        ])));

        $manifest = $client->fetch(self::URL);

        self::assertInstanceOf(ReleaseManifest::class, $manifest);
        self::assertSame('4.1.0-rc.1', $manifest->version);
        self::assertNull($manifest->notesUrl);
        self::assertNull($manifest->releasedAt);
        self::assertSame(['4.0.11'], $manifest->yankedVersions);
    }

    public function testSuccessfulFetchIsCachedAndForceBypassesIt(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"schema": 1, "stable": {"version": "4.0.13"}}'),
            new MockResponse('{"schema": 1, "stable": {"version": "4.0.14"}}'),
        ]);
        $client = new UpdateManifestClient($httpClient, new ArrayAdapter(), new NullLogger());

        $first = $client->fetch(self::URL);
        $cached = $client->fetch(self::URL);
        $forced = $client->fetch(self::URL, force: true);

        self::assertInstanceOf(ReleaseManifest::class, $first);
        self::assertInstanceOf(ReleaseManifest::class, $cached);
        self::assertInstanceOf(ReleaseManifest::class, $forced);
        self::assertSame('4.0.13', $first->version);
        self::assertSame('4.0.13', $cached->version);
        self::assertSame('4.0.14', $forced->version);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    /**
     * A bare GET: no query parameters, no instance identifier, no telemetry.
     */
    public function testRequestCarriesNoIdentifyingData(): void
    {
        $seen = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"schema": 1, "stable": {"version": "4.0.13"}}');
        });

        (new UpdateManifestClient($httpClient, new ArrayAdapter(), new NullLogger()))->fetch(self::URL);

        self::assertIsArray($seen);
        self::assertSame('GET', $seen['method']);
        self::assertSame(self::URL, $seen['url']);
        self::assertStringNotContainsString('?', $seen['url']);
        self::assertSame([], $seen['options']['query'] ?? []);
        self::assertSame('', (string) ($seen['options']['body'] ?? ''));
    }

    private function clientFor(MockResponse $response): UpdateManifestClient
    {
        return new UpdateManifestClient(
            new MockHttpClient($response),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }
}
