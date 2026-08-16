<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\CalDavClient;
use App\Service\Dav\WebDavClient;
use App\Service\Destination\CalDavDestinationProvider;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\ShareableFile;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CalDavDestinationProviderTest extends TestCase
{
    private const CALENDAR_URL = 'https://93.184.216.34/remote.php/dav/calendars/ada/personal';

    private const EMPTY_MULTISTATUS = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>';

    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'ics').'.ics';
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testDeliversEveryEventWithADeterministicUid(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $captured = [];
        $provider = $this->provider([
            new MockResponse(self::EMPTY_MULTISTATUS, ['http_code' => 207]), // UID query e0
            new MockResponse('', ['http_code' => 201]),                      // PUT e0
            new MockResponse(self::EMPTY_MULTISTATUS, ['http_code' => 207]), // UID query e1
            new MockResponse('', ['http_code' => 201]),                      // PUT e1
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 33]);

        self::assertTrue($result->ok);
        self::assertSame('2', $result->context['created']);
        self::assertSame('0', $result->context['skipped']);
        self::assertStringContainsString('UID:synaplan-f5-m33-e0@synaplan', $captured[1]['body']);
        self::assertStringContainsString('BEGIN:VCALENDAR', $captured[1]['body']);
        self::assertStringContainsString('SUMMARY:Standup', $captured[1]['body']);
        self::assertStringContainsString('UID:synaplan-f5-m33-e1@synaplan', $captured[3]['body']);
    }

    public function testRedeliveryDoesNotDuplicateEvents(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $found = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/e0.ics</d:href></d:response></d:multistatus>';
        $provider = $this->provider([
            new MockResponse($found, ['http_code' => 207]),                  // e0 already there → skip
            new MockResponse(self::EMPTY_MULTISTATUS, ['http_code' => 207]), // e1 not found by query
            new MockResponse('', ['http_code' => 412]),                      // …but PUT loses the race → still no duplicate
        ]);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 33]);

        self::assertTrue($result->ok, 'already-delivered events are a success, not an error');
        self::assertSame('0', $result->context['created']);
        self::assertSame('2', $result->context['skipped']);
    }

    public function testAFileWithoutEventsIsUnsupported(): void
    {
        file_put_contents($this->tempFile, 'just a text file');

        $provider = $this->provider([]);
        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unsupported, $result->code);
    }

    public function testAWebdavConnectionIsRejectedForCalendarDelivery(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $connection = new Connection(1, 'webdav', 'Files, not a calendar');
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('findByIdAndOwner')->willReturn($connection);

        $provider = new CalDavDestinationProvider(
            new CalDavClient(new WebDavClient(new MockHttpClient([]), new SsrfGuard())),
            $connections,
            $this->createStub(CredentialVaultInterface::class),
        );

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    private function icsWithTwoEvents(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Synaplan//Calendar 1.0//EN',
            'BEGIN:VEVENT',
            'UID:original-1@synaplan',
            'DTSTART:20260817T070000Z',
            'DTEND:20260817T080000Z',
            'SUMMARY:Standup',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:original-2@synaplan',
            'DTSTART:20260818T090000Z',
            'DTEND:20260818T100000Z',
            'SUMMARY:Planning',
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";
    }

    private function file(): ShareableFile
    {
        return new ShareableFile(5, 1, $this->tempFile, 'meetings.ics', 1024);
    }

    /**
     * @param list<MockResponse>                                          $responses
     * @param list<array{method: string, url: string, body: string}>|null $captured
     */
    private function provider(array $responses, ?array &$captured = null): CalDavDestinationProvider
    {
        $connection = new Connection(1, 'caldav', 'My calendar');
        $connection->setConfig(['base_url' => self::CALENDAR_URL, 'username' => 'ada']);
        $connection->setCredentialId(42);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->expects(self::any())->method('findByIdAndOwner')->with(7, 1)->willReturn($connection);

        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects(self::any())->method('reveal')->with(42, 1)->willReturn('app-password');

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                ];
            }

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new CalDavDestinationProvider(
            new CalDavClient(new WebDavClient($http, new SsrfGuard())),
            $connections,
            $vault,
        );
    }
}
