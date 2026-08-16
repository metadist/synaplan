<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dav;

use App\Service\Dav\CalDavClient;
use App\Service\Dav\DavTarget;
use App\Service\Dav\WebDavClient;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CalDavClientTest extends TestCase
{
    private const CALENDAR_URL = 'https://93.184.216.34/remote.php/dav/calendars/ada/personal';

    public function testEventExistsQueriesTheUidAndReadsTheMultistatus(): void
    {
        $captured = [];
        $found = <<<XML
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/remote.php/dav/calendars/ada/personal/e1.ics</d:href></d:response>
            </d:multistatus>
            XML;
        $client = $this->client([
            new MockResponse($found, ['http_code' => 207]),
            new MockResponse('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>', ['http_code' => 207]),
        ], $captured);

        self::assertTrue($client->eventExists($this->target(), 'synaplan-f1-m2-e0@synaplan'));
        self::assertFalse($client->eventExists($this->target(), 'synaplan-f1-m2-e1@synaplan'));

        self::assertSame('REPORT', $captured[0]['method']);
        self::assertStringContainsString('synaplan-f1-m2-e0@synaplan', $captured[0]['body']);
        self::assertStringContainsString('calendar-query', $captured[0]['body']);
    }

    public function testPutEventIsCreateOnlyAndAddressedByUid(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse('', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 412]),
        ], $captured);

        self::assertTrue($client->putEvent($this->target(), 'synaplan-f1-m2-e0@synaplan', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"));
        self::assertFalse($client->putEvent($this->target(), 'synaplan-f1-m2-e0@synaplan', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"), 'an existing UID is not an error');

        self::assertSame('PUT', $captured[0]['method']);
        self::assertStringEndsWith('/synaplan-f1-m2-e0%40synaplan.ics', $captured[0]['url']);
        self::assertContains('If-None-Match: *', $captured[0]['options']['headers']);
    }

    private function target(): DavTarget
    {
        return new DavTarget(self::CALENDAR_URL, 'ada', 'app-password');
    }

    /**
     * @param list<MockResponse>                                                                 $responses
     * @param list<array{method: string, url: string, body: string, options: array<mixed>}>|null $captured
     */
    private function client(array $responses, ?array &$captured = null): CalDavClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                    'options' => $options,
                ];
            }

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new CalDavClient(new WebDavClient($http, new SsrfGuard()));
    }
}
