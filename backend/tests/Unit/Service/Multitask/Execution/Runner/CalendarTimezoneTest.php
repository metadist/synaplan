<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\UserRepository;
use App\Service\Calendar\CalendarEventService;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\CalendarEventRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Timezone precedence for wall-clock event times (#2010).
 *
 * A "tomorrow at 10" request once produced a calendar event stamped UTC on a
 * UTC server, shifting the event by the user's offset in every connected
 * calendar. The runner now resolves the zone as: user-named zone in this
 * message > stored profile zone > planner zone (unless UTC) > ask instead of
 * writing. A planner UTC/Z must never override a stored profile zone.
 */
final class CalendarTimezoneTest extends TestCase
{
    private const USER_ID = 1;

    private ?string $storedIcs = null;

    protected function tearDown(): void
    {
        $this->storedIcs = null;
    }

    public function testProfileZoneWinsOverPlannerUtc(): void
    {
        $result = $this->runCalendar(
            messageText: 'Dentist tomorrow at 10',
            params: ['title' => 'Dentist', 'start' => '2026-09-19T10:00:00', 'timezone' => 'UTC'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertStringContainsString(
            'Calendar invite "Dentist" — 2026-09-19 10:00 (Europe/Berlin)',
            (string) $result->text
        );
        // 10:00 CEST (UTC+2) lands on the correct UTC instant in the .ics.
        self::assertStringContainsString('DTSTART:20260919T080000Z', (string) $this->storedIcs);
    }

    public function testPlannerZDoesNotOverrideTheStoredProfileZone(): void
    {
        $result = $this->runCalendar(
            messageText: 'Dentist tomorrow at 10',
            params: ['title' => 'Dentist', 'start' => '2026-09-19T10:00:00Z', 'timezone' => 'UTC'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(Europe/Berlin)', (string) $result->text);
        self::assertStringContainsString('DTSTART:20260919T080000Z', (string) $this->storedIcs);
    }

    public function testProfileZoneBeatsAServerSidePlannerGuess(): void
    {
        $result = $this->runCalendar(
            messageText: 'Dentist tomorrow at 10',
            params: ['title' => 'Dentist', 'start' => '2026-09-19T10:00:00', 'timezone' => 'America/New_York'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(Europe/Berlin)', (string) $result->text);
    }

    public function testUserNamedUtcWinsOverTheStoredProfileZone(): void
    {
        $result = $this->runCalendar(
            messageText: 'Deploy review tomorrow at 10:00 UTC',
            params: ['title' => 'Deploy review', 'start' => '2026-09-19T10:00:00Z', 'timezone' => 'UTC'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString(
            'Calendar invite "Deploy review" — 2026-09-19 10:00 (UTC)',
            (string) $result->text
        );
        self::assertStringContainsString('DTSTART:20260919T100000Z', (string) $this->storedIcs);
    }

    public function testUserNamedCityZoneWinsOverTheStoredProfileZone(): void
    {
        $result = $this->runCalendar(
            messageText: 'Call with New York office tomorrow at 10',
            params: ['title' => 'NY call', 'start' => '2026-09-19T10:00:00', 'timezone' => 'America/New_York'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(America/New_York)', (string) $result->text);
        // 10:00 EDT (UTC-4) == 14:00Z.
        self::assertStringContainsString('DTSTART:20260919T140000Z', (string) $this->storedIcs);
    }

    public function testUnknownTimezoneAsksInsteadOfWritingSilentUtc(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::never())->method('storeRawContent');

        $runner = $this->runner($storage, null);
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Dentist',
            'start' => '2026-09-19T10:00:00',
            'timezone' => 'UTC',
        ]);

        $result = $runner->run($node, $this->context('Dentist tomorrow at 10'));

        // Successful text-only result: reply nodes only see `.text`, so the
        // ask must travel as text, not as a failure swallowed by a fallback.
        self::assertTrue($result->isSuccessful());
        self::assertCount(0, $result->files);
        self::assertStringContainsString('I did not create "Dentist"', (string) $result->text);
        self::assertStringContainsString('Europe/Berlin', (string) $result->text);
        self::assertFalse($result->metadata['calendar_event']['created'] ?? true);
        self::assertSame('unknown_timezone', $result->metadata['calendar_event']['reason'] ?? null);
    }

    public function testMissingPlannerZoneWithoutProfileAsksAsWell(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::never())->method('storeRawContent');

        $runner = $this->runner($storage, null);
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Dentist',
            'start' => '2026-09-19T10:00:00',
        ]);

        $result = $runner->run($node, $this->context('Dentist tomorrow at 10'));

        self::assertTrue($result->isSuccessful());
        self::assertCount(0, $result->files);
        self::assertStringContainsString('I did not create "Dentist"', (string) $result->text);
    }

    public function testUtcInsideAnotherWordDoesNotCorroborateUtc(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::never())->method('storeRawContent');

        $runner = $this->runner($storage, null);
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Production review',
            'start' => '2026-09-19T10:00:00',
            'timezone' => 'UTC',
        ]);

        // "production" contains "utc" — only a bare UTC/GMT token counts.
        $result = $runner->run($node, $this->context('Production review tomorrow at 10'));

        self::assertCount(0, $result->files);
        self::assertStringContainsString('I did not create "Production review"', (string) $result->text);
    }

    public function testInvalidPlannerZoneFallsBackToProfile(): void
    {
        $result = $this->runCalendar(
            messageText: 'Dentist tomorrow at 10',
            params: ['title' => 'Dentist', 'start' => '2026-09-19T10:00:00', 'timezone' => 'Mars/Olympus'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(Europe/Berlin)', (string) $result->text);
    }

    public function testUserNamedUtcBeatsPlannerZoneAndProfile(): void
    {
        // The planner missed the explicit mention and emitted Berlin anyway —
        // the user's own "10:00 UTC" still wins.
        $result = $this->runCalendar(
            messageText: 'Deploy review tomorrow at 10:00 UTC',
            params: ['title' => 'Deploy review', 'start' => '2026-09-19T10:00:00', 'timezone' => 'Europe/Berlin'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString(
            'Calendar invite "Deploy review" — 2026-09-19 10:00 (UTC)',
            (string) $result->text
        );
        self::assertStringContainsString('DTSTART:20260919T100000Z', (string) $this->storedIcs);
    }

    public function testUserNamedIanaZoneBeatsPlannerZoneAndProfile(): void
    {
        $result = $this->runCalendar(
            messageText: 'Call tomorrow at 10, America/New_York',
            params: ['title' => 'NY call', 'start' => '2026-09-19T10:00:00', 'timezone' => 'Europe/Berlin'],
            profileTimezone: 'Europe/Berlin',
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(America/New_York)', (string) $result->text);
        self::assertStringContainsString('DTSTART:20260919T140000Z', (string) $this->storedIcs);
    }

    public function testLowercaseIanaZoneIsNormalized(): void
    {
        $result = $this->runCalendar(
            messageText: 'Dentist tomorrow at 10, europe/berlin',
            params: ['title' => 'Dentist', 'start' => '2026-09-19T10:00:00', 'timezone' => 'UTC'],
            profileTimezone: null,
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('(Europe/Berlin)', (string) $result->text);
    }

    public function testSlashLookalikeDoesNotExtractAZone(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::never())->method('storeRawContent');

        $runner = $this->runner($storage, null);
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Sync',
            'start' => '2026-09-19T10:00:00',
            'timezone' => 'UTC',
        ]);

        // "and/or" looks like a path but is not a zone — still ask.
        $result = $runner->run($node, $this->context('Sync and/or review tomorrow at 10'));

        self::assertCount(0, $result->files);
        self::assertStringContainsString('I did not create "Sync"', (string) $result->text);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runCalendar(string $messageText, array $params, ?string $profileTimezone): NodeResult
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('storeRawContent')->willReturnCallback(
            function (string $content): array {
                $this->storedIcs = $content;

                return ['success' => true, 'path' => '1/000/meeting.ics', 'size' => strlen($content), 'mime' => 'text/calendar'];
            }
        );

        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], $params);

        return $this->runner($storage, $profileTimezone)->run($node, $this->context($messageText));
    }

    private function runner(FileStorageService $storage, ?string $profileTimezone): CalendarEventRunner
    {
        $users = $this->createMock(UserRepository::class);
        if (null === $profileTimezone) {
            $users->method('find')->willReturn(null);
        } else {
            $user = new User();
            $user->setUserDetails(['timezone' => $profileTimezone]);
            $users->method('find')->willReturn($user);
        }

        return new CalendarEventRunner(
            new CalendarEventService(),
            $storage,
            new RequestedCalendarDelivery(
                $this->createMock(ConnectionRepository::class),
                new DestinationRegistry([]),
                new PlannerChannelCatalog($this->createMock(ConnectionRepository::class)),
                $this->createMock(LoggerInterface::class),
            ),
            $users,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function context(string $text): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getFileText')->willReturn('');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getChatId')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return new NodeContext($message, [], self::USER_ID, ['language' => 'en']);
    }
}
