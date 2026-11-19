<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Connection;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\UserRepository;
use App\Service\Calendar\CalendarEntry;
use App\Service\Calendar\CalendarEntryExtraction;
use App\Service\Calendar\CalendarEntryExtractor;
use App\Service\Calendar\CalendarEventService;
use App\Service\Calendar\CalendarEventSpeech;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Destination\DestinationProvider;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\Destination\ShareableFile;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\CalendarEntrySource;
use App\Service\Multitask\Execution\Runner\CalendarEventRunner;
use App\Service\Multitask\Execution\Runner\ComposeReplyRunner;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Trage bitte die 3 Flüge in mein Outlook Calender", with a picture of the
 * booking. The message names no hour; the picture does.
 *
 * The calendar step used to answer every such request with the question
 * about the time, because it only looked for a clock time in the latest
 * message. It now takes its entries from what was read: the step it depends
 * on, else the files of the turn. An entry counts only when that text names
 * its time, so the step still never writes an hour nobody gave it.
 */
final class CalendarReadEntriesTest extends TestCase
{
    private const USER_ID = 1;

    private const REQUEST = 'Trage bitte die 3 Flüge in mein Outlook Calender';

    /** An invented booking: no real journey, number or traveller. */
    private const BOOKING = "Flug 1: Freitag, 12. März 2027, 06:45 – 08:20, Hamburg Flughafen (HAM) → Wien (VIE)\n"
        ."Flug 2: Samstag, 13. März 2027, 19:30 – 22:15, Wien (VIE) → Lissabon (LIS)\n"
        ."Flug 3: Mittwoch, 17. März 2027, 14:00 – 17:00, Lissabon (LIS) → Hamburg Flughafen (HAM)\n"
        .'Buchungsnummer 4471-205-918';

    /** @var list<array{content: string, filename: string}> */
    private array $stored = [];

    /** @var list<array{request: string, read: string}> */
    private array $readCalls = [];

    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/calread_'.uniqid();
        mkdir($this->uploadDir.'/1/000', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/1/000/*') ?: []);
        @rmdir($this->uploadDir.'/1/000');
        @rmdir($this->uploadDir.'/1');
        @rmdir($this->uploadDir);
    }

    public function testTheFlightsOfAnEarlierStepAreEnteredInOneStep(): void
    {
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $result = $this->runner($this->flights())->run($this->readingNode(), $context);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame([['request' => self::REQUEST, 'read' => self::BOOKING]], $this->readCalls);

        self::assertCount(1, $result->files, 'one .ics for all three flights');
        self::assertCount(1, $this->stored);
        self::assertSame('calendar_20270312_3_entries.ics', $this->stored[0]['filename']);
        $ics = $this->stored[0]['content'];
        self::assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        // 06:45 in Berlin in mid-March (CET, UTC+1).
        self::assertStringContainsString('DTSTART:20270312T054500Z', $ics);
        self::assertStringContainsString('DTEND:20270312T072000Z', $ics);
        self::assertStringContainsString('DTSTART:20270313T183000Z', $ics);
        self::assertStringContainsString('DTSTART:20270317T130000Z', $ics);
        self::assertStringContainsString('Buchungsnummer 4471-205-918', $ics);

        self::assertCount(3, $result->metadata['calendar_events']);
        self::assertSame('Flug Hamburg (HAM) → Wien (VIE)', $result->metadata['calendar_event']['title']);
        self::assertSame('Europe/Berlin', $result->metadata['calendar_event']['timezone']);
        self::assertSame(CalendarEntrySource::key(self::BOOKING), $result->metadata[CalendarEntrySource::META_READ]);
    }

    /**
     * No calendar is connected. The reply says so once and the file is
     * attached in the same turn — not eight messages later.
     */
    public function testWithoutAConnectedCalendarTheReplySaysSoAndAttachesTheFile(): void
    {
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $result = $this->runner($this->flights())->run($this->readingNode(), $context);

        self::assertSame(
            "Ich habe 3 Kalendereinträge erstellt:\n\n"
            ."- 12.03.2027, 06:45–08:20 Uhr: Flug Hamburg (HAM) → Wien (VIE)\n"
            ."- 13.03.2027, 19:30–22:15 Uhr: Flug Wien (VIE) → Lissabon (LIS)\n"
            ."- 17.03.2027, 14:00–17:00 Uhr: Flug Lissabon (LIS) → Hamburg (HAM)\n\n"
            .'Sie wurden nicht in einen Kalender eingetragen — die .ics-Datei ist angehängt. Verbinde einen Kalender unter Apps → Microsoft 365.',
            $result->text,
        );
        self::assertFalse($result->metadata['calendar_delivery']['ok']);
        self::assertCount(1, $result->files);
    }

    public function testWithAConnectedOutlookTheEntriesGoThereInOneDelivery(): void
    {
        $provider = $this->outlookProvider(3);
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $result = $this->runner($this->flights(), delivery: $this->outlookDelivery($provider))->run($this->readingNode(), $context);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertStringStartsWith('Ich habe 3 Kalendereinträge in Outlook hinzugefügt:', (string) $result->text);
        self::assertStringNotContainsString('nicht in einen Kalender', (string) $result->text);
        self::assertSame(1, $provider->sent, 'one file, one delivery');
        self::assertSame(3, substr_count((string) $provider->lastContent, 'BEGIN:VEVENT'));
        self::assertTrue($result->metadata['calendar_delivery']['ok']);
        self::assertSame('outlook', $result->metadata['calendar_delivery']['channel']);
        self::assertSame(3, $result->metadata['calendar_delivery']['created']);
        self::assertCount(1, $result->files, 'the .ics stays attached');
    }

    /**
     * The planner did not plan a reading step. The picture's text was
     * extracted when it was uploaded, and that is what the step reads.
     */
    public function testTheTextOfAnAttachedFileIsReadWhenNoStepReadIt(): void
    {
        $message = $this->message(self::REQUEST);
        $message->addFile($this->file(self::BOOKING));

        $result = $this->runner($this->flights())->run(
            new TaskNode('n1', Capability::CalendarEvent, [], [], ['timezone' => 'Europe/Berlin']),
            $this->context($message),
        );

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame(self::BOOKING, $this->readCalls[0]['read']);
        self::assertCount(3, $result->metadata['calendar_events']);
    }

    /**
     * "Ja, trage ein, was Du hast" carries no file. The picture of the first
     * message is still what the conversation is about.
     */
    public function testAFollowUpReadsThePictureAttachedEarlier(): void
    {
        $first = $this->message(self::REQUEST, id: 10);
        $first->addFile($this->file(self::BOOKING));
        $reply = $this->message('Could you please provide the dates and times?', direction: 'OUT', id: 11);

        $result = $this->runner($this->flights())->run(
            new TaskNode('n1', Capability::CalendarEvent, [], [], ['timezone' => 'Europe/Berlin']),
            $this->context($this->message('Ja, trage ein, was Du hast', id: 12), [$first, $reply]),
        );

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame(self::BOOKING, $this->readCalls[0]['read']);
        self::assertSame('Ja, trage ein, was Du hast', $this->readCalls[0]['request']);
        self::assertCount(3, $result->metadata['calendar_events']);
        self::assertArrayNotHasKey('calendar_delivery', $result->metadata, 'this message names no calendar');
    }

    /**
     * The planner wrote 09:00 next to a booking that says 06:45. The person
     * named no hour, so the planner's is not used: the entries are read.
     */
    public function testAStartNobodyNamedIsReplacedByWhatWasRead(): void
    {
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $node = new TaskNode('n2', Capability::CalendarEvent, ['n1'], [], [
            'title' => 'Flüge',
            'start' => '2027-03-12T09:00:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $result = $this->runner($this->flights())->run($node, $context);

        self::assertCount(1, $this->readCalls);
        self::assertStringNotContainsString('DTSTART:20270312T080000Z', $this->stored[0]['content'], '09:00 Berlin was never written');
        self::assertStringContainsString('DTSTART:20270312T054500Z', $this->stored[0]['content']);
        self::assertCount(3, $result->metadata['calendar_events']);
    }

    /**
     * The person typed the time. That is theirs, whatever is attached: the
     * event is written as asked and nothing is read.
     */
    public function testAStartThePersonNamedIsWrittenWithoutReading(): void
    {
        $message = $this->message('Trag den Flug morgen um 06:45 ein');
        $message->addFile($this->file(self::BOOKING));
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Flug',
            'start' => '2027-03-12T06:45:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $result = $this->runner($this->flights())->run($node, $this->context($message));

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame([], $this->readCalls);
        self::assertSame(1, substr_count($this->stored[0]['content'], 'BEGIN:VEVENT'));
        self::assertStringStartsWith('meeting_', $this->stored[0]['filename']);
        self::assertArrayNotHasKey('calendar_events', $result->metadata);
    }

    /**
     * "Termin um 15 Uhr" — "an welchem Tag?" — "morgen". The hour was named
     * one message earlier. Asking for it again is the ping-pong.
     */
    public function testAnHourNamedOneMessageEarlierIsNotAskedForAgain(): void
    {
        $thread = [
            $this->message('Trag einen Termin um 15 Uhr ein', id: 10),
            $this->message('An welchem Tag?', direction: 'OUT', id: 11),
        ];
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Termin',
            'start' => '2027-03-12T15:00:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $result = $this->runner($this->flights())->run($node, $this->context($this->message('morgen', id: 12), $thread));

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertCount(1, $result->files);
        self::assertStringContainsString('15:00 Uhr', (string) $result->text);
        self::assertSame([], $this->readCalls);
    }

    public function testWhenNothingWasFoundTheStepAsksWhichAppointment(): void
    {
        $context = $this->context($this->message('Trag das in meinen Kalender ein'));
        $context->setResult('n1', NodeResult::ok('Ein Foto einer Katze auf einem Sofa.'));

        $result = $this->runner([])->run($this->readingNode(), $context);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->files);
        self::assertSame([], $this->stored);
        self::assertStringContainsString('keinen Termin mit Datum und Uhrzeit', (string) $result->text);
        self::assertSame('no_entries_found', $result->metadata['calendar_event']['reason']);
        self::assertSame('testprovider', $result->metadata['provider'], 'the reading call is still shown and metered');
    }

    /**
     * The planner sometimes plans one step per flight. Each depends on the
     * same reading step; the second and third must not enter everything again.
     */
    public function testASecondStepThatReadsTheSameTextEntersNothing(): void
    {
        $runner = $this->runner($this->flights());
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $context->setResult('n2', $runner->run($this->readingNode('n2'), $context));
        $second = $runner->run($this->readingNode('n3'), $context);

        self::assertCount(1, $this->readCalls);
        self::assertCount(1, $this->stored);
        self::assertTrue($second->isSuccessful());
        self::assertNull($second->text);
        self::assertSame([], $second->files);
        self::assertSame('already_entered', $second->metadata['calendar_event']['reason']);
    }

    public function testAFailedReadingFailsTheStepWithoutWritingAnything(): void
    {
        $extractor = $this->createStub(CalendarEntryExtractor::class);
        $extractor->method('extract')->willThrowException(new \RuntimeException('provider said 529'));
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $result = $this->runnerWith($extractor)->run($this->readingNode(), $context);

        self::assertFalse($result->isSuccessful());
        self::assertSame([], $this->stored);
        self::assertStringNotContainsString('529', (string) $result->error, 'provider internals stay in the log');
    }

    /**
     * A wall-clock time means nothing without a zone. Asked before the
     * reading call, so nothing is spent on entries that cannot be placed.
     */
    public function testAnUnknownTimezoneAsksBeforeAnythingIsRead(): void
    {
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $node = new TaskNode('n2', Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], []);

        $result = $this->runner($this->flights(), profileTimezone: null)->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $this->readCalls);
        self::assertSame([], $this->stored);
        self::assertSame('unknown_timezone', $result->metadata['calendar_event']['reason']);
    }

    /**
     * An install that does not wire the reader keeps today's answer: the
     * question about the time.
     */
    public function testWithoutAReaderTheStepAsksForTheTime(): void
    {
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $runner = new CalendarEventRunner(
            new CalendarEventService(),
            $this->storage(),
            $this->noCalendar(),
            $this->users('Europe/Berlin'),
            new NullLogger(),
        );
        $result = $runner->run($this->readingNode(), $context);

        self::assertSame('missing_clock_time', $result->metadata['calendar_event']['reason']);
        self::assertSame([], $this->stored);
    }

    /**
     * An approval names every entry it is asked for. The resumed step then
     * writes those entries, not the result of a second reading.
     */
    public function testAnApprovalListsTheEntriesAndTheResumedStepWritesThem(): void
    {
        $asked = null;
        $askedTool = null;
        $gate = $this->createStub(StepApprovalGate::class);
        $gate->method('consult')->willReturnCallback(function (NodeContext $context, TaskNode $node, string $tool, array $arguments) use (&$asked, &$askedTool): ?NodeResult {
            if ($context->isApproved($node->id)) {
                return null;
            }
            $asked = $arguments;
            $askedTool = $tool;

            return NodeResult::waitingApproval(5, $arguments);
        });
        $runner = $this->runner($this->flights(), gate: $gate);
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $waiting = $runner->run($this->readingNode(), $context);

        self::assertTrue($waiting->isWaitingApproval());
        self::assertSame(0, $this->storedCount(), 'nothing is written before the approval');
        self::assertIsArray($asked);
        self::assertSame('skill:calendar_event', $askedTool);
        self::assertStringStartsWith('3 × 2027-03-12 06:45 Flug Hamburg (HAM) → Wien (VIE); ', $asked['summary']);
        self::assertSame('Europe/Berlin', $asked['timezone']);
        self::assertCount(3, $asked['entries']);
        self::assertSame('2027-03-12T06:45:00+01:00', $asked['entries'][0]['start']);
        self::assertSame('2027-03-12T08:20:00+01:00', $asked['entries'][0]['end']);

        // DagExecutor::resume() merges the approved arguments into the node's params.
        $context->markApproved('n2');
        $resumed = new TaskNode('n2', Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], ['timezone' => 'Europe/Berlin'] + $asked);
        $result = $runner->run($resumed, $context);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertCount(1, $this->readCalls, 'the approved entries are written, the text is not read again');
        self::assertSame(3, substr_count($this->stored[0]['content'], 'BEGIN:VEVENT'));
        self::assertStringContainsString('DTSTART:20270312T054500Z', $this->stored[0]['content']);
    }

    /**
     * The first step waits for its approval. A second step that would read
     * the same text does not read it again and asks for nothing.
     */
    public function testWhileAStepWaitsForApprovalASecondStepAsksForNothing(): void
    {
        $asked = 0;
        $gate = $this->createStub(StepApprovalGate::class);
        $gate->method('consult')->willReturnCallback(static function (NodeContext $context, TaskNode $node, string $tool, array $arguments) use (&$asked): NodeResult {
            ++$asked;

            return NodeResult::waitingApproval(5, $arguments, ['tool' => $tool]);
        });
        $runner = $this->runner($this->flights(), gate: $gate);
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $waiting = $runner->run($this->readingNode('n2'), $context);
        $context->setResult('n2', $waiting);
        $second = $runner->run($this->readingNode('n3'), $context);

        self::assertTrue($waiting->isWaitingApproval());
        self::assertSame(5, $waiting->metadata['approval_id'], 'the approval itself is untouched');
        self::assertSame('skill:calendar_event', $waiting->metadata['tool']);
        self::assertSame(CalendarEntrySource::key(self::BOOKING), $waiting->metadata[CalendarEntrySource::META_READ]);

        self::assertTrue($second->isSuccessful());
        self::assertSame('already_entered', $second->metadata['calendar_event']['reason']);
        self::assertSame(1, $asked, 'one approval for the entries, not one per step');
        self::assertCount(1, $this->readCalls);
        self::assertSame(0, $this->storedCount());
    }

    /**
     * An approval that showed one event is an approval for that event. A
     * list the plan itself carried is not written on the strength of it.
     */
    public function testAnApprovalForOneEventNeverWritesAListThePlanCarried(): void
    {
        $asked = null;
        $gate = $this->createStub(StepApprovalGate::class);
        $gate->method('consult')->willReturnCallback(static function (NodeContext $context, TaskNode $node, string $tool, array $arguments) use (&$asked): ?NodeResult {
            if ($context->isApproved($node->id)) {
                return null;
            }
            $asked = $arguments;

            return NodeResult::waitingApproval(5, $arguments);
        });
        $planned = [
            'title' => 'Zahnarzt',
            'start' => '2027-03-12T15:00:00',
            'timezone' => 'Europe/Berlin',
            'entries' => [
                ['title' => 'Nicht gezeigt', 'start' => '2027-03-20T08:00:00+01:00', 'end' => '2027-03-20T09:00:00+01:00'],
                ['title' => 'Auch nicht', 'start' => '2027-03-21T08:00:00+01:00', 'end' => '2027-03-21T09:00:00+01:00'],
            ],
        ];
        $runner = $this->runner($this->flights(), gate: $gate);
        $context = $this->context($this->message('Trag morgen um 15 Uhr den Zahnarzt ein'));

        $waiting = $runner->run(new TaskNode('n1', Capability::CalendarEvent, [], [], $planned), $context);

        self::assertTrue($waiting->isWaitingApproval());
        self::assertIsArray($asked);
        self::assertSame('Zahnarzt', $asked['title']);
        self::assertSame([], $asked['entries'], 'the approval states that it is for this one event');

        // DagExecutor::resume(): the step's own params, replaced by the approved arguments.
        $context->markApproved('n1');
        $result = $runner->run(new TaskNode('n1', Capability::CalendarEvent, [], [], array_merge($planned, $asked)), $context);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame(1, substr_count($this->stored[0]['content'], 'BEGIN:VEVENT'));
        self::assertStringContainsString('SUMMARY:Zahnarzt', $this->stored[0]['content']);
        self::assertStringNotContainsString('Nicht gezeigt', $this->stored[0]['content']);
        self::assertSame([], $this->readCalls);
    }

    /**
     * The resumed step carries what it entered, like the step that asked.
     * A step that reads the same text for the same calendar afterwards
     * enters nothing.
     */
    public function testAResumedStepStillStandsForWhatItEntered(): void
    {
        $gate = $this->createStub(StepApprovalGate::class);
        $gate->method('consult')->willReturnCallback(static fn (NodeContext $context, TaskNode $node, string $tool, array $arguments): ?NodeResult => $context->isApproved($node->id) ? null : NodeResult::waitingApproval(5, $arguments));
        $runner = $this->runner($this->flights(), gate: $gate);
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));

        $waiting = $runner->run($this->readingNode('n2'), $context);
        $context->markApproved('n2');
        $resumed = new TaskNode('n2', Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], ['timezone' => 'Europe/Berlin'] + $waiting->metadata['approved_args']);
        $written = $runner->run($resumed, $context);
        $context->setResult('n2', $written);
        $later = $runner->run($this->readingNode('n3'), $context);

        self::assertTrue($written->isSuccessful(), (string) $written->error);
        self::assertSame(CalendarEntrySource::key(self::BOOKING), $written->metadata[CalendarEntrySource::META_READ]);
        self::assertSame('already_entered', $later->metadata['calendar_event']['reason']);
        self::assertSame(1, $this->storedCount());
        self::assertCount(1, $this->readCalls);
    }

    /**
     * Two approvals for the same entries can exist from a run before this
     * rule. The second one to resume finds the entries written.
     */
    public function testAResumedStepWritesNothingAFinishedStepAlreadyEntered(): void
    {
        $gate = $this->createStub(StepApprovalGate::class);
        $gate->method('consult')->willReturn(null);
        $runner = $this->runner($this->flights(), gate: $gate);
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $context->setResult('n2', $runner->run($this->readingNode('n2'), $context));
        self::assertSame(1, $this->storedCount());

        $context->markApproved('n3');
        $approved = ['timezone' => 'Europe/Berlin', 'entries' => [
            ['title' => 'Flug Hamburg (HAM) → Wien (VIE)', 'start' => '2027-03-12T06:45:00+01:00', 'end' => '2027-03-12T08:20:00+01:00'],
        ]];
        $second = $runner->run(new TaskNode('n3', Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], $approved), $context);

        self::assertTrue($second->isSuccessful());
        self::assertSame('already_entered', $second->metadata['calendar_event']['reason']);
        self::assertSame(1, $this->storedCount(), 'no second file');
    }

    /**
     * "Into Outlook and into my Nextcloud calendar": two steps read the same
     * booking, each for its own calendar. Both deliver.
     */
    public function testTheSameBookingGoesIntoTwoCalendarsOnceEach(): void
    {
        $outlook = $this->calendarProvider('m365_calendar', 3);
        $caldav = $this->calendarProvider('caldav', 3);
        $runner = $this->runner($this->flights(), delivery: $this->twoCalendars($outlook, $caldav));
        $context = $this->context($this->message('Trage die Flüge in Outlook und in meinen Nextcloud-Kalender ein'));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $node = static fn (string $id, string $channel): TaskNode => new TaskNode($id, Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], ['timezone' => 'Europe/Berlin', 'channel' => $channel]);

        $first = $runner->run($node('n2', 'outlook'), $context);
        $context->setResult('n2', $first);
        $second = $runner->run($node('n3', 'calendar'), $context);
        $context->setResult('n3', $second);
        $repeat = $runner->run($node('n4', 'Outlook'), $context);

        self::assertSame(1, $outlook->sent);
        self::assertSame(1, $caldav->sent);
        self::assertStringStartsWith('Ich habe 3 Kalendereinträge in Outlook hinzugefügt:', (string) $first->text);
        self::assertStringStartsWith('Ich habe 3 Kalendereinträge in deinen Kalender hinzugefügt:', (string) $second->text);
        self::assertNotSame($first->metadata[CalendarEntrySource::META_READ], $second->metadata[CalendarEntrySource::META_READ]);
        self::assertSame('already_entered', $repeat->metadata['calendar_event']['reason'], 'the same calendar a second time');
    }

    /**
     * Two steps that depend on the same two reading steps read the same
     * text, whichever order each lists them in.
     */
    public function testTheOrderOfTheStepsReadDoesNotMakeASecondReading(): void
    {
        $runner = $this->runner($this->flights());
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $context->setResult('n2', NodeResult::ok('Hotel Wien, Check-in 15:00'));
        $node = static fn (string $id, array $dependsOn): TaskNode => new TaskNode($id, Capability::CalendarEvent, $dependsOn, [], ['timezone' => 'Europe/Berlin']);

        $context->setResult('n3', $runner->run($node('n3', ['n1', 'n2']), $context));
        $second = $runner->run($node('n4', ['n2', 'n1']), $context);

        self::assertCount(1, $this->readCalls);
        self::assertSame('already_entered', $second->metadata['calendar_event']['reason']);
        self::assertSame(1, $this->storedCount());
    }

    /**
     * The reply names every calendar step. The one that entered the flights
     * speaks; the one that had nothing to add leaves no gap.
     */
    public function testTheReplyShowsTheStepThatWroteAndSkipsTheOneThatDidNot(): void
    {
        $runner = $this->runner($this->flights());
        $context = $this->context($this->message(self::REQUEST));
        $context->setResult('n1', NodeResult::ok(self::BOOKING));
        $context->setResult('n2', $runner->run($this->readingNode('n2'), $context));
        $context->setResult('n3', $runner->run($this->readingNode('n3'), $context));

        $plan = CalendarEventSpeech::adjustPlan([
            'language' => 'de',
            'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'file_analysis', 'depends_on' => []],
                ['id' => 'n2', 'capability' => 'calendar_event', 'depends_on' => ['n1'], 'inputs' => ['source' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'calendar_event', 'depends_on' => ['n1'], 'inputs' => ['source' => '$n1.text']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3'], 'inputs' => ['text' => 'Fertig.']],
            ],
        ], self::REQUEST);
        $reply = (new ComposeReplyRunner())->run(
            new TaskNode('n4', Capability::ComposeReply, ['n2', 'n3'], $plan['tasks'][3]['inputs'], []),
            $context,
        );

        self::assertSame(['$n2.text', '$n3.text'], $plan['tasks'][3]['inputs']['text']);
        self::assertSame($context->getResult('n2')?->text, $reply->text);
    }

    private function storedCount(): int
    {
        return count($this->stored);
    }

    /**
     * @return list<CalendarEntry>
     */
    private function flights(): array
    {
        return [
            new CalendarEntry('Flug Hamburg (HAM) → Wien (VIE)', '2027-03-12T06:45:00', '2027-03-12T08:20:00', 'Hamburg Flughafen (HAM)', 'Buchungsnummer 4471-205-918'),
            new CalendarEntry('Flug Wien (VIE) → Lissabon (LIS)', '2027-03-13T19:30:00', '2027-03-13T22:15:00', 'Wien (VIE)'),
            new CalendarEntry('Flug Lissabon (LIS) → Hamburg (HAM)', '2027-03-17T14:00:00', '2027-03-17T17:00:00'),
        ];
    }

    private function readingNode(string $id = 'n2'): TaskNode
    {
        return new TaskNode($id, Capability::CalendarEvent, ['n1'], ['source' => '$n1.text'], ['timezone' => 'Europe/Berlin']);
    }

    /**
     * @param list<CalendarEntry> $entries what the reading returns
     */
    private function runner(
        array $entries,
        ?RequestedCalendarDelivery $delivery = null,
        ?string $profileTimezone = 'Europe/Berlin',
        ?StepApprovalGate $gate = null,
    ): CalendarEventRunner {
        $extractor = $this->createStub(CalendarEntryExtractor::class);
        $extractor->method('extract')->willReturnCallback(function (string $request, string $readText) use ($entries): CalendarEntryExtraction {
            $this->readCalls[] = ['request' => $request, 'read' => $readText];

            return new CalendarEntryExtraction($entries, 0, [
                'provider' => 'testprovider',
                'model' => 'analytics-model',
                'model_id' => 31,
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 90, 'total_tokens' => 490],
                'facade_metered' => true,
                'facade_charged_cost' => '0.001200',
                'facade_price_known' => true,
            ]);
        });

        return $this->runnerWith($extractor, $delivery, $profileTimezone, $gate);
    }

    private function runnerWith(
        CalendarEntryExtractor $extractor,
        ?RequestedCalendarDelivery $delivery = null,
        ?string $profileTimezone = 'Europe/Berlin',
        ?StepApprovalGate $gate = null,
    ): CalendarEventRunner {
        return new CalendarEventRunner(
            new CalendarEventService(),
            $this->storage(),
            $delivery ?? $this->noCalendar(),
            $this->users($profileTimezone),
            new NullLogger(),
            uploadDir: $this->uploadDir,
            approvalGate: $gate,
            entrySource: new CalendarEntrySource($extractor, new NullLogger()),
        );
    }

    private function storage(): FileStorageService
    {
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturnCallback(function (string $content, ?int $userId, string $filename): array {
            $this->stored[] = ['content' => $content, 'filename' => $filename];
            $relative = '1/000/'.$filename;
            file_put_contents($this->uploadDir.'/'.$relative, $content);

            return ['success' => true, 'path' => $relative, 'size' => strlen($content), 'mime' => 'text/calendar', 'error' => null];
        });

        return $storage;
    }

    private function users(?string $profileTimezone): UserRepository
    {
        $users = $this->createStub(UserRepository::class);
        if (null === $profileTimezone) {
            $users->method('find')->willReturn(null);

            return $users;
        }
        $user = new User();
        $user->setUserDetails(['timezone' => $profileTimezone]);
        $users->method('find')->willReturn($user);

        return $users;
    }

    private function noCalendar(): RequestedCalendarDelivery
    {
        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findByOwner')->willReturn([]);

        return new RequestedCalendarDelivery(
            $connections,
            new DestinationRegistry([]),
            new PlannerChannelCatalog($connections),
            new NullLogger(),
        );
    }

    private function outlookDelivery(DestinationProvider $provider): RequestedCalendarDelivery
    {
        $m365 = new Connection(self::USER_ID, Connection::TYPE_M365, 'ada@contoso.com');
        $m365->setStatus(Connection::STATUS_CONNECTED);
        $m365->setConfig(['channel' => 'm365', 'channel_calendar' => 'outlook']);
        $m365->setScopes(['Calendars.ReadWrite']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($m365, 3);

        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findByOwner')->willReturn([$m365]);
        $connections->method('findByIdAndOwner')->willReturn($m365);

        return new RequestedCalendarDelivery(
            $connections,
            new DestinationRegistry([$provider]),
            new PlannerChannelCatalog($connections),
            new NullLogger(),
        );
    }

    /**
     * An Outlook calendar and a CalDAV calendar, as the channels "outlook"
     * and "calendar".
     */
    private function twoCalendars(DestinationProvider $outlook, DestinationProvider $caldav): RequestedCalendarDelivery
    {
        $m365 = new Connection(self::USER_ID, Connection::TYPE_M365, 'ada@contoso.com');
        $m365->setStatus(Connection::STATUS_CONNECTED);
        $m365->setConfig(['channel' => 'm365', 'channel_calendar' => 'outlook']);
        $m365->setScopes(['Calendars.ReadWrite']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($m365, 3);

        $nextcloud = new Connection(self::USER_ID, 'caldav', 'Nextcloud');
        $nextcloud->setStatus(Connection::STATUS_CONNECTED);
        $nextcloud->setConfig(['channel' => 'calendar']);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($nextcloud, 4);

        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findByOwner')->willReturn([$m365, $nextcloud]);
        $connections->method('findByIdAndOwner')->willReturnCallback(
            static fn (int $id): ?Connection => match ($id) {
                3 => $m365,
                4 => $nextcloud,
                default => null,
            },
        );

        return new RequestedCalendarDelivery(
            $connections,
            new DestinationRegistry([$outlook, $caldav]),
            new PlannerChannelCatalog($connections),
            new NullLogger(),
        );
    }

    /**
     * @return DestinationProvider&object{sent: int, lastContent: string|null}
     */
    private function outlookProvider(int $created): DestinationProvider
    {
        return $this->calendarProvider('m365_calendar', $created);
    }

    /**
     * @return DestinationProvider&object{sent: int, lastContent: string|null}
     */
    private function calendarProvider(string $id, int $created): DestinationProvider
    {
        return new class($id, $created) implements DestinationProvider {
            public int $sent = 0;

            public ?string $lastContent = null;

            public function __construct(private readonly string $id, private readonly int $created)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                ++$this->sent;
                $this->lastContent = (string) file_get_contents($file->absolutePath);

                return DestinationResult::success((string) $this->created, [
                    'created' => (string) $this->created,
                    'skipped' => '0',
                    'webLink' => 'https://outlook.office.com/evt',
                ]);
            }
        };
    }

    /**
     * @param array<int, Message> $thread
     */
    private function context(Message $message, array $thread = []): NodeContext
    {
        return new NodeContext($message, $thread, self::USER_ID, ['language' => 'de']);
    }

    private function message(string $text, string $direction = 'IN', ?int $id = null): Message
    {
        $message = new Message();
        $message->setUserId(self::USER_ID);
        $message->setText($text);
        $message->setDirection($direction);
        $message->setLanguage('de');
        $message->setFile(0);
        if (null !== $id) {
            (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);
        }

        return $message;
    }

    private function file(string $text): File
    {
        return (new File())
            ->setFileName('booking.png')
            ->setFileType('png')
            ->setFileText($text);
    }
}
