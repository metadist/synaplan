<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Calendar\CalendarEventService;
use App\Service\Destination\CalendarDeliveryId;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\OutlookCalendarDestinationProvider;
use App\Service\Destination\ShareableFile;
use App\Service\Microsoft\GraphClient;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `m365_calendar` destination: .ics events land in the connected Outlook
 * calendar via Graph, idempotent through a deterministic `transactionId`
 * (409 = already delivered), and pre-expansion consents (no
 * `Calendars.ReadWrite` in the granted scopes) are refused with a readable
 * missing-scope reason instead of a Graph 403.
 */
final class OutlookCalendarDestinationProviderTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'ics').'.ics';
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testDeliversEveryEventWithADeterministicTransactionId(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $captured = [];
        $provider = $this->provider([
            new MockResponse(json_encode(['id' => 'evt-0', 'webLink' => 'https://outlook.office.com/evt-0']), ['http_code' => 201]),
            new MockResponse(json_encode(['id' => 'evt-1', 'webLink' => 'https://outlook.office.com/evt-1']), ['http_code' => 201]),
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 33]);

        self::assertTrue($result->ok);
        self::assertSame('2', $result->context['created']);
        self::assertSame('0', $result->context['skipped']);
        self::assertSame('https://outlook.office.com/evt-1', $result->context['webLink']);

        $first = json_decode($captured[0]['body'], true);
        self::assertSame(
            CalendarDeliveryId::token(33, 0, 'Standup', '2026-08-17T07:00:00', '2026-08-17T08:00:00'),
            $first['transactionId'],
        );
        self::assertSame('Standup', $first['subject']);
        self::assertSame('2026-08-17T07:00:00', $first['start']['dateTime']);
        self::assertSame('UTC', $first['start']['timeZone']);
        $second = json_decode($captured[1]['body'], true);
        self::assertSame(
            CalendarDeliveryId::token(33, 1, 'Planning', '2026-08-18T09:00:00', '2026-08-18T10:00:00'),
            $second['transactionId'],
        );
        self::assertNotSame($first['transactionId'], $second['transactionId']);
    }

    public function testTwoInvitesInOneMessageDoNotShareATransactionId(): void
    {
        $prague = tempnam(sys_get_temp_dir(), 'ics').'.ics';
        $malta = tempnam(sys_get_temp_dir(), 'ics').'.ics';
        file_put_contents($prague, $this->singleEvent('Flug Prag', '20261121T194000Z', '20261121T214000Z'));
        file_put_contents($malta, $this->singleEvent('Flug Malta', '20261125T140000Z', '20261125T160000Z'));

        try {
            $captured = [];
            $provider = $this->provider([
                new MockResponse(json_encode(['id' => 'evt-prague', 'webLink' => 'https://outlook.office.com/prague']), ['http_code' => 201]),
                new MockResponse(json_encode(['id' => 'evt-malta', 'webLink' => 'https://outlook.office.com/malta']), ['http_code' => 201]),
            ], $captured);

            $pragueResult = $provider->send(new ShareableFile(0, 1, $prague, 'prague.ics', 128), ['connection_id' => 7, 'message_id' => 99]);
            $maltaResult = $provider->send(new ShareableFile(0, 1, $malta, 'malta.ics', 128), ['connection_id' => 7, 'message_id' => 99]);

            self::assertTrue($pragueResult->ok);
            self::assertTrue($maltaResult->ok);
            $first = json_decode($captured[0]['body'], true);
            $second = json_decode($captured[1]['body'], true);
            self::assertNotSame($first['transactionId'], $second['transactionId']);
            self::assertSame('Flug Prag', $first['subject']);
            self::assertSame('Flug Malta', $second['subject']);
        } finally {
            @unlink($prague);
            @unlink($malta);
        }
    }

    public function testTheFileWrittenForSeveralEntriesBecomesOneOutlookEventEach(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $flight = static fn (string $title, string $start, string $end, string $from): array => [
            'title' => $title,
            'start' => new \DateTimeImmutable($start, $berlin),
            'end' => new \DateTimeImmutable($end, $berlin),
            'description' => 'Buchungsnummer 4471-205-918, bestätigt; zwei Erwachsene, Gepäck laut Buchung',
            'location' => $from,
        ];
        file_put_contents($this->tempFile, (new CalendarEventService())->buildIcsForEntries([
            $flight('Flug Hamburg (HAM) → Wien (VIE)', '2027-03-12 06:45', '2027-03-12 08:20', 'Hamburg Flughafen (HAM)'),
            $flight('Flug Wien (VIE) → Lissabon (LIS)', '2027-03-13 19:30', '2027-03-13 22:15', 'Wien (VIE)'),
            $flight('Flug Lissabon (LIS) → Hamburg (HAM)', '2027-03-17 14:00', '2027-03-17 17:00', 'Lissabon (LIS)'),
        ], 'Europe/Berlin'));

        $captured = [];
        $provider = $this->provider([
            new MockResponse(json_encode(['id' => 'evt-0', 'webLink' => 'https://outlook.office.com/evt-0']), ['http_code' => 201]),
            new MockResponse(json_encode(['id' => 'evt-1', 'webLink' => 'https://outlook.office.com/evt-1']), ['http_code' => 201]),
            new MockResponse(json_encode(['id' => 'evt-2', 'webLink' => 'https://outlook.office.com/evt-2']), ['http_code' => 201]),
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 41]);

        self::assertTrue($result->ok);
        self::assertSame('3', $result->context['created']);
        self::assertCount(3, $captured);

        $events = array_map(static fn (array $request): array => json_decode($request['body'], true), $captured);
        self::assertSame(
            ['Flug Hamburg (HAM) → Wien (VIE)', 'Flug Wien (VIE) → Lissabon (LIS)', 'Flug Lissabon (LIS) → Hamburg (HAM)'],
            array_column($events, 'subject'),
        );
        // Berlin is UTC+1 in mid-March: the printed 06:45 is 05:45 UTC.
        self::assertSame(
            ['2027-03-12T05:45:00', '2027-03-13T18:30:00', '2027-03-17T13:00:00'],
            array_map(static fn (array $event): string => $event['start']['dateTime'], $events),
        );
        self::assertSame(
            ['2027-03-12T07:20:00', '2027-03-13T21:15:00', '2027-03-17T16:00:00'],
            array_map(static fn (array $event): string => $event['end']['dateTime'], $events),
        );
        self::assertSame('Hamburg Flughafen (HAM)', $events[0]['location']['displayName']);
        // The description is longer than one folded line; it arrives whole.
        self::assertStringContainsString(
            "Buchungsnummer 4471-205-918, bestätigt; zwei Erwachsene, Gepäck laut Buchung\nTime zone: Europe/Berlin",
            $events[0]['body']['content'],
        );
        self::assertCount(3, array_unique(array_column($events, 'transactionId')));
    }

    public function testRedeliveryCountsConflictsAsSkippedNotFailed(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $provider = $this->provider([
            new MockResponse('{"error":{"code":"conflict"}}', ['http_code' => 409]),
            new MockResponse('{"error":{"code":"conflict"}}', ['http_code' => 409]),
        ]);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 33]);

        self::assertTrue($result->ok, 'already-delivered events are a success, not an error');
        self::assertSame('0', $result->context['created']);
        self::assertSame('2', $result->context['skipped']);
    }

    public function testAttendeesAndDescriptionAreMappedOntoTheGraphEvent(): void
    {
        file_put_contents($this->tempFile, implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:x@synaplan',
            'DTSTART:20260817T070000Z',
            'DTEND:20260817T080000Z',
            'SUMMARY:Marketing Strategy',
            'DESCRIPTION:Prep notes\\nTime zone: Europe/Berlin',
            'LOCATION:Room 5',
            'ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:sanam@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n");

        $captured = [];
        $provider = $this->provider([
            new MockResponse(json_encode(['id' => 'evt', 'webLink' => 'https://outlook.office.com/evt']), ['http_code' => 201]),
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7, 'message_id' => 1]);

        self::assertTrue($result->ok);
        $payload = json_decode($captured[0]['body'], true);
        self::assertSame('Marketing Strategy', $payload['subject']);
        self::assertSame('Room 5', $payload['location']['displayName']);
        self::assertStringContainsString("Prep notes\nTime zone: Europe/Berlin", $payload['body']['content']);
        self::assertSame('sanam@example.com', $payload['attendees'][0]['emailAddress']['address']);
    }

    public function testMissingCalendarScopeIsARefusalWithAReason(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $provider = $this->provider([], scopes: ['User.Read', 'Mail.Read']);
        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
        self::assertSame('missing_scope', $result->context['reason']);
    }

    public function testANonM365ConnectionIsRejected(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $connection = new Connection(1, 'caldav', 'Not Outlook');
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('findByIdAndOwner')->willReturn($connection);

        $provider = new OutlookCalendarDestinationProvider(
            $this->graphClient([]),
            $connections,
            new NullLogger(),
        );

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    public function testAFileWithoutEventsIsUnsupported(): void
    {
        file_put_contents($this->tempFile, 'just a text file');

        $provider = $this->provider([]);
        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unsupported, $result->code);
    }

    public function testAGraph403IsMappedToUnauthorized(): void
    {
        file_put_contents($this->tempFile, $this->icsWithTwoEvents());

        $provider = $this->provider([
            new MockResponse('{"error":{"code":"ErrorAccessDenied"}}', ['http_code' => 403]),
        ]);

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    private function singleEvent(string $summary, string $start, string $end): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:one@synaplan',
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$summary,
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";
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
     * @param list<string>                                                $scopes
     */
    private function provider(array $responses, ?array &$captured = null, array $scopes = ['User.Read', 'Mail.Read', 'Calendars.ReadWrite']): OutlookCalendarDestinationProvider
    {
        $connection = new Connection(1, Connection::TYPE_M365, 'Microsoft 365');
        $connection->setConfig(['provider' => Connection::TYPE_M365]);
        $connection->setScopes($scopes);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->expects(self::any())->method('findByIdAndOwner')->with(7, 1)->willReturn($connection);

        return new OutlookCalendarDestinationProvider(
            $this->graphClient($responses, $captured),
            $connections,
            new NullLogger(),
        );
    }

    /**
     * @param list<MockResponse>                                          $responses
     * @param list<array{method: string, url: string, body: string}>|null $captured
     */
    private function graphClient(array $responses, ?array &$captured = null): GraphClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                ];
            }

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-1');

        return new GraphClient($http, $tokens, new NullLogger(), static function (int $seconds): void {});
    }
}
