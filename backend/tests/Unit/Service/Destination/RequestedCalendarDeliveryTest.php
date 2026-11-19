<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DestinationProvider;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\Destination\ShareableFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Channel-to-calendar resolution for the `calendar_event` runner: the planner
 * names a calendar channel ("outlook", "calendar") and the delivery routes
 * the .ics to the matching provider — m365 → `m365_calendar`, caldav →
 * `caldav` — with honest failures for unknown channels and missing scopes.
 */
final class RequestedCalendarDeliveryTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'ics').'.ics';
        file_put_contents($this->tempFile, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testDeliversToTheOutlookProviderForAnM365CalendarChannel(): void
    {
        $m365 = $this->m365Connection();
        $provider = $this->recordingProvider('m365_calendar', DestinationResult::success('1', [
            'created' => '1',
            'skipped' => '0',
            'webLink' => 'https://outlook.office.com/evt',
        ]));

        $result = $this->delivery([$m365], [$provider])->send(1, $this->tempFile, 'meeting.ics', 42, 'outlook');

        self::assertTrue($result['ok']);
        self::assertSame('outlook', $result['channel']);
        self::assertSame(1, $result['created']);
        self::assertSame('https://outlook.office.com/evt', $result['webLink']);
        self::assertStringContainsString('Added the event to outlook', $result['message']);
        self::assertSame(['connection_id' => 3, 'message_id' => 42], $provider->lastParams);
    }

    public function testUnknownChannelFailsWithoutTouchingAnyProvider(): void
    {
        $result = $this->delivery([$this->m365Connection()], [])->send(1, $this->tempFile, 'meeting.ics', 42, 'invented');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no calendar with this name', $result['message']);
    }

    public function testAMailChannelIsNotACalendarTarget(): void
    {
        // "m365" resolves to the MAIL channel of the connection — calendar
        // delivery must refuse it (only kind calendar is a valid target).
        $result = $this->delivery([$this->m365Connection()], [])->send(1, $this->tempFile, 'meeting.ics', 42, 'm365');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no calendar with this name', $result['message']);
    }

    public function testMissingScopeFailureIsTranslatedToAReconnectHint(): void
    {
        $provider = $this->recordingProvider('m365_calendar', DestinationResult::failure(
            DestinationFailureCode::Unauthorized,
            ['connection' => 'ada@contoso.com', 'reason' => 'missing_scope'],
        ));

        $result = $this->delivery([$this->m365Connection()], [$provider])->send(1, $this->tempFile, 'meeting.ics', 42, 'outlook');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('reconnect it under Settings', $result['message']);
    }

    public function testAlreadyDeliveredEventsReadAsAlreadyExists(): void
    {
        $provider = $this->recordingProvider('m365_calendar', DestinationResult::success('0', [
            'created' => '0',
            'skipped' => '1',
        ]));

        $result = $this->delivery([$this->m365Connection()], [$provider])->send(1, $this->tempFile, 'meeting.ics', 42, 'outlook');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('already exists', $result['message']);
    }

    public function testCaldavChannelRoutesToTheCaldavProvider(): void
    {
        $caldav = new Connection(1, 'caldav', 'personal');
        $caldav->setConfig(['channel' => 'calendar']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($caldav, 9);

        $provider = $this->recordingProvider('caldav', DestinationResult::success('1', ['created' => '1', 'skipped' => '0']));

        $result = $this->delivery([$caldav], [$provider])->send(1, $this->tempFile, 'meeting.ics', 42, 'calendar');

        self::assertTrue($result['ok']);
        self::assertSame(['connection_id' => 9, 'message_id' => 42], $provider->lastParams);
    }

    public function testDetectsExplicitPutInCalendarRequests(): void
    {
        $delivery = $this->delivery([], []);

        self::assertTrue($delivery->userAskedToPutInCalendar(
            'Create a meeting reminder called Nextcloud Test for tomorrow at 9:00 and put it in my calendar'
        ));
        self::assertTrue($delivery->userAskedToPutInCalendar('add it to my nextcloud calendar'));
        self::assertTrue($delivery->userAskedToPutInCalendar('put it into calendar'));
        self::assertTrue($delivery->userAskedToPutInCalendar('lege den Termin in meinen Kalender'));
        self::assertFalse($delivery->userAskedToPutInCalendar('Create a meeting reminder called Nextcloud Test for tomorrow at 9:00'));
        self::assertFalse($delivery->userAskedToPutInCalendar('What is a calendar?'));
        self::assertFalse($delivery->userAskedToPutInCalendar('Do you support Outlook?'));
        self::assertFalse($delivery->userAskedToPutInCalendar('save the .ics file'));
        self::assertFalse($delivery->userAskedToPutInCalendar('do not put it in my calendar'));
        self::assertFalse($delivery->userAskedToPutInCalendar('lege den Termin nicht in meinen Kalender'));
    }

    /**
     * The destination counts, whatever the verb. "Eintragen" is how German
     * says it, and a list of verbs did not have it.
     */
    public function testTheDestinationCountsWhateverTheVerb(): void
    {
        $delivery = $this->delivery([], []);

        self::assertTrue($delivery->userAskedToPutInCalendar('Trage bitte die 3 Flüge in mein Outlook Calender'));
        self::assertTrue($delivery->userAskedToPutInCalendar('Gut, bitte in meinen m365 calendar eintragen'));
        self::assertTrue($delivery->userAskedToPutInCalendar('schreib den Termin in den Kalender'));
        self::assertTrue($delivery->userAskedToPutInCalendar('enter it into my calendar'));
        self::assertTrue($delivery->userAskedToPutInCalendar('ponlo en mi calendario'));
        self::assertTrue($delivery->userAskedToPutInCalendar('ajoute-le à mon calendrier'));
        self::assertTrue($delivery->userAskedToPutInCalendar('takvimime ekle'));

        self::assertFalse($delivery->userAskedToPutInCalendar('Ja, trage ein, was Du hast'), 'names no calendar');
        self::assertFalse($delivery->userAskedToPutInCalendar('Create an Outlook invite for tomorrow at 10'), 'an invite to download');
        self::assertFalse(
            $delivery->userAskedToPutInCalendar('Meeting in Berlin über den neuen Kalender der Firma morgen um 10'),
            'a calendar mentioned further on is not a destination',
        );
    }

    /**
     * "Stop asking, enter it now" was read as "not into the calendar",
     * because any "nicht" in the message counted as a refusal.
     */
    public function testARefusalCountsOnlyNextToTheCalendar(): void
    {
        $delivery = $this->delivery([], []);

        self::assertTrue($delivery->userAskedToPutInCalendar(
            'Hoer auf zu fragen trage ein Trage die Daten, die du hast, jetzt in meinen Kalender ein in M365. Jetzt eintragen, nicht mehr fragen.'
        ));
        self::assertTrue($delivery->userAskedToPutInCalendar('I have no time tomorrow, add the meeting to my calendar at 15:00'));

        self::assertFalse($delivery->userAskedToPutInCalendar('Erstelle den Termin, aber ohne Kalender'));
        self::assertFalse($delivery->userAskedToPutInCalendar('no lo pongas en mi calendario'));
        self::assertFalse($delivery->userAskedToPutInCalendar('ne pas ajouter à mon calendrier'));
    }

    public function testDefaultCalendarChannelUsesTheOnlyConnectedCalendar(): void
    {
        $caldav = new Connection(1, 'caldav', 'personal');
        $caldav->setStatus(Connection::STATUS_CONNECTED);
        $caldav->setConfig(['channel' => 'calendar']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($caldav, 9);

        self::assertSame('calendar', $this->delivery([$caldav], [])->defaultCalendarChannel(1));
    }

    public function testDefaultCalendarChannelIsNullWhenNoneAreConnected(): void
    {
        self::assertNull($this->delivery([], [])->defaultCalendarChannel(1));
    }

    public function testDefaultCalendarChannelIgnoresDisconnectedCalendars(): void
    {
        $stale = new Connection(1, 'caldav', 'old');
        $stale->setStatus(Connection::STATUS_DISCONNECTED);
        $stale->setConfig(['channel' => 'calendar']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($stale, 8);

        $healthy = $this->m365Connection();
        $healthy->setStatus(Connection::STATUS_CONNECTED);

        self::assertSame('outlook', $this->delivery([$stale, $healthy], [])->defaultCalendarChannel(1));
        self::assertNull($this->delivery([$stale], [])->defaultCalendarChannel(1));
    }

    public function testDefaultCalendarChannelPrefersTheCalendarSlug(): void
    {
        $outlook = $this->m365Connection();
        $outlook->setStatus(Connection::STATUS_CONNECTED);
        $caldav = new Connection(1, 'caldav', 'personal');
        $caldav->setStatus(Connection::STATUS_CONNECTED);
        $caldav->setConfig(['channel' => 'calendar']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($caldav, 9);

        self::assertSame('calendar', $this->delivery([$outlook, $caldav], [])->defaultCalendarChannel(1));
    }

    private function m365Connection(): Connection
    {
        $m365 = new Connection(1, Connection::TYPE_M365, 'ada@contoso.com');
        $m365->setConfig(['channel' => 'm365', 'channel_calendar' => 'outlook']);
        $m365->setScopes(['Calendars.ReadWrite']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($m365, 3);

        return $m365;
    }

    /**
     * @return DestinationProvider&object{lastParams: array<string, mixed>|null}
     */
    private function recordingProvider(string $id, DestinationResult $result): DestinationProvider
    {
        return new class($id, $result) implements DestinationProvider {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;

            public function __construct(private readonly string $providerId, private readonly DestinationResult $result)
            {
            }

            public function id(): string
            {
                return $this->providerId;
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                $this->lastParams = $params;

                return $this->result;
            }
        };
    }

    /**
     * @param list<Connection>          $connections
     * @param list<DestinationProvider> $providers
     */
    private function delivery(array $connections, array $providers): RequestedCalendarDelivery
    {
        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByOwner')->willReturn($connections);
        $repo->method('findByIdAndOwner')->willReturnCallback(
            static function (int $id, int $ownerId) use ($connections): ?Connection {
                foreach ($connections as $connection) {
                    if ($connection->getId() === $id) {
                        return $connection;
                    }
                }

                return null;
            },
        );

        return new RequestedCalendarDelivery(
            $repo,
            new DestinationRegistry($providers),
            new PlannerChannelCatalog($repo),
            new NullLogger(),
        );
    }
}
