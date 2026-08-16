<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dav;

use App\Service\Dav\DavException;
use App\Service\Dav\DavTarget;
use App\Service\Dav\WebDavClient;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebDavClientTest extends TestCase
{
    /**
     * A literal public IP keeps the SSRF guard's DNS resolution out of the
     * test — deterministic and offline.
     */
    private const BASE_URL = 'https://93.184.216.34/remote.php/dav/files/ada';
    private const PASSWORD = 'app-password-xyz';

    public function testPutUploadsWithBasicAuthAndEncodedPath(): void
    {
        $captured = [];
        $client = $this->client([new MockResponse('', ['http_code' => 201])], $captured);

        $status = $client->put($this->target(), 'Synaplan/Bericht Küche.docx', 'content', 'application/octet-stream');

        self::assertSame(201, $status);
        self::assertSame('PUT', $captured[0]['method']);
        self::assertSame(self::BASE_URL.'/Synaplan/Bericht%20K%C3%BCche.docx', $captured[0]['url']);
    }

    public function testExistsIsTrueOnMultistatusAndFalseOn404(): void
    {
        $client = $this->client([
            new MockResponse('<multistatus/>', ['http_code' => 207]),
            new MockResponse('', ['http_code' => 404]),
        ]);

        self::assertTrue($client->exists($this->target(), 'Synaplan'));
        self::assertFalse($client->exists($this->target(), 'Synaplan'));
    }

    public function testMkcolTreatsAnExistingCollectionAsFine(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 405])]);

        $client->mkcol($this->target(), 'Synaplan');
        $this->expectNotToPerformAssertions();
    }

    public function testCreateOnlyPutReports412InsteadOfThrowing(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 412])]);

        $status = $client->put($this->target(), 'e1.ics', 'BEGIN:VCALENDAR', 'text/calendar', ['If-None-Match' => '*']);

        self::assertSame(412, $status);
    }

    public function testAuthFailureThrowsWithoutLeakingTheCredential(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 401])]);

        try {
            $client->put($this->target(), 'x.txt', 'content', 'text/plain');
            self::fail('Expected a DavException');
        } catch (DavException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function testPrivateAddressesAreBlockedBeforeAnyRequest(): void
    {
        $client = $this->client([]);
        $target = new DavTarget('https://127.0.0.1/remote.php/dav/files/ada', 'ada', self::PASSWORD);

        $this->expectException(DavException::class);
        $this->expectExceptionMessage('private or reserved network');
        $client->exists($target, '');
    }

    public function testPlainHttpIsRejected(): void
    {
        $client = $this->client([]);
        $target = new DavTarget('http://93.184.216.34/remote.php/dav/files/ada', 'ada', self::PASSWORD);

        $this->expectException(DavException::class);
        $this->expectExceptionMessage('https');
        $client->exists($target, '');
    }

    private function target(): DavTarget
    {
        return new DavTarget(self::BASE_URL, 'ada', self::PASSWORD);
    }

    /**
     * @param list<MockResponse>                                                   $responses
     * @param list<array{method: string, url: string, options: array<mixed>}>|null $captured
     */
    private function client(array $responses, ?array &$captured = null): WebDavClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];
            }

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new WebDavClient($http, new SsrfGuard());
    }
}
