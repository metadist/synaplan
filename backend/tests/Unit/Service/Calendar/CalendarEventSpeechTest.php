<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\CalendarEventSpeech;
use App\Service\Calendar\CalendarTurnEvidence;
use PHPUnit\Framework\TestCase;

final class CalendarEventSpeechTest extends TestCase
{
    public function testGermanConfirmationNamesDateTimeAndDuration(): void
    {
        $start = new \DateTimeImmutable('tomorrow 15:00:00', new \DateTimeZone('Europe/Berlin'));
        $text = CalendarEventSpeech::confirmed('de', $start, 60, 'outlook', 'https://outlook.example/e', 'Bahnfahrt');

        self::assertStringContainsString('Kalendereintrag', $text);
        self::assertStringContainsString('Bahnfahrt', $text);
        self::assertStringContainsString('morgen', $text);
        self::assertStringContainsString('15:00 Uhr', $text);
        self::assertStringContainsString('eine Stunde', $text);
        self::assertStringContainsString('Outlook', $text);
        self::assertStringContainsString('https://outlook.example/e', $text);
    }

    public function testAdjustPlanReplacesInventedCalendarEventWhenNoClockTime(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                [
                    'id' => 'n1',
                    'capability' => 'calendar_event',
                    'params' => ['start' => '2026-11-06T09:00:00'],
                ],
                [
                    'id' => 'n2',
                    'capability' => 'compose_reply',
                    'depends_on' => ['n1'],
                    'inputs' => ['text' => 'Here is your meeting invite for tomorrow at 09:00.'],
                ],
            ],
        ];

        $out = CalendarEventSpeech::adjustPlan(
            $payload,
            'stelle mir einen termin für eine bahnfahrt morgen in meinen kalender',
        );

        self::assertSame('de', $out['language']);
        self::assertSame('n1', $out['reply_node']);
        self::assertCount(1, $out['tasks']);
        self::assertSame('compose_reply', $out['tasks'][0]['capability']);
        self::assertStringContainsString('Uhrzeit', $out['tasks'][0]['inputs']['text']);
    }

    public function testAdjustPlanPointsComposeReplyAtTheRunnerText(): void
    {
        $payload = [
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'calendar_event', 'params' => ['start' => '2026-06-10T09:00:00']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'inputs' => ['text' => 'Here is your meeting invite.']],
            ],
        ];

        $out = CalendarEventSpeech::adjustPlan($payload, 'Meeting tomorrow at 9:00');

        self::assertSame('$n1.text', $out['tasks'][1]['inputs']['text']);
    }

    /**
     * "Trage bitte die 3 Flüge in mein Outlook Calender" names no hour. The
     * picture does, and the calendar step depends on the step that reads it.
     * Replacing this plan with the question about the time is what made the
     * request impossible.
     */
    public function testAdjustPlanKeepsACalendarStepThatReadsFromAnEarlierStep(): void
    {
        $out = CalendarEventSpeech::adjustPlan($this->flightPlan(), 'Trage bitte die 3 Flüge in mein Outlook Calender');

        self::assertSame(['file_analysis', 'calendar_event', 'compose_reply'], array_column($out['tasks'], 'capability'));
        self::assertSame('n3', $out['reply_node']);
        self::assertSame('$n1.text', $out['tasks'][1]['inputs']['source']);
        self::assertSame('outlook', $out['tasks'][1]['params']['channel']);
        self::assertSame('$n2.text', $out['tasks'][2]['inputs']['text']);
    }

    /**
     * A start the planner wrote although the person named no hour is not
     * theirs. The step can read, so the start goes and the step stays.
     */
    public function testAdjustPlanTakesAnInventedStartOffAStepThatCanRead(): void
    {
        $plan = $this->flightPlan();
        $plan['tasks'][1]['params'] += ['start' => '2027-03-12T09:00:00', 'end' => '2027-03-12T10:00:00', 'duration_minutes' => 60];

        $out = CalendarEventSpeech::adjustPlan($plan, 'Trage bitte die 3 Flüge in mein Outlook Calender');

        self::assertCount(3, $out['tasks']);
        self::assertArrayNotHasKey('start', $out['tasks'][1]['params']);
        self::assertArrayNotHasKey('end', $out['tasks'][1]['params']);
        self::assertSame(60, $out['tasks'][1]['params']['duration_minutes']);
        self::assertSame('Europe/Berlin', $out['tasks'][1]['params']['timezone']);
    }

    /**
     * The planner forgot the reading step, but the turn has an attached
     * file with text. The runner reads that, so the plan stays.
     */
    public function testAdjustPlanKeepsACalendarStepWhenTheTurnHasFileText(): void
    {
        $plan = [
            'language' => 'de',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'calendar_event', 'params' => ['start' => '2027-03-12T09:00:00', 'timezone' => 'Europe/Berlin']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['text' => 'Erledigt.']],
            ],
        ];

        $out = CalendarEventSpeech::adjustPlan(
            $plan,
            'Trage bitte die 3 Flüge in mein Outlook Calender',
            new CalendarTurnEvidence('Trage bitte die 3 Flüge in mein Outlook Calender', '', '06:45 Hamburg → 08:20 Wien'),
        );

        self::assertSame(['calendar_event', 'compose_reply'], array_column($out['tasks'], 'capability'));
        self::assertArrayNotHasKey('start', $out['tasks'][0]['params']);
        self::assertSame('$n1.text', $out['tasks'][1]['inputs']['text']);
    }

    public function testAdjustPlanStillAsksWhenThereIsNothingToRead(): void
    {
        $plan = [
            'language' => 'de',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'calendar_event', 'params' => ['title' => 'Bahnfahrt']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
            ],
        ];

        $out = CalendarEventSpeech::adjustPlan($plan, 'termin morgen in den kalender', new CalendarTurnEvidence('termin morgen in den kalender'));

        self::assertCount(1, $out['tasks']);
        self::assertSame('compose_reply', $out['tasks'][0]['capability']);
        self::assertStringContainsString('Uhrzeit', $out['tasks'][0]['inputs']['text']);
    }

    /**
     * "Termin um 15 Uhr" — "an welchem Tag?" — "morgen". The start the
     * planner wrote carries the hour the person named one message earlier.
     */
    public function testAdjustPlanKeepsAStartThePersonNamedEarlier(): void
    {
        $plan = [
            'language' => 'de',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'calendar_event', 'params' => ['start' => '2027-03-12T15:00:00', 'timezone' => 'Europe/Berlin']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['text' => 'ok']],
            ],
        ];

        $kept = CalendarEventSpeech::adjustPlan($plan, 'morgen', new CalendarTurnEvidence('morgen', 'Trag einen Termin um 15 Uhr ein'));
        self::assertSame('2027-03-12T15:00:00', $kept['tasks'][0]['params']['start']);

        $plan['tasks'][0]['params']['start'] = '2027-03-12T09:00:00';
        $asked = CalendarEventSpeech::adjustPlan($plan, 'morgen', new CalendarTurnEvidence('morgen', 'Trag einen Termin um 15 Uhr ein'));
        self::assertCount(1, $asked['tasks'], 'an hour nobody named is still not written');
        self::assertStringContainsString('Uhrzeit', $asked['tasks'][0]['inputs']['text']);
    }

    /**
     * Three events the person named the times of, three steps. The reply
     * confirmed the first one only.
     */
    public function testAdjustPlanPointsComposeReplyAtEveryCalendarStep(): void
    {
        $plan = [
            'language' => 'en',
            'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'calendar_event', 'params' => ['start' => '2027-03-12T06:45:00']],
                ['id' => 'n2', 'capability' => 'calendar_event', 'params' => ['start' => '2027-03-13T19:30:00']],
                ['id' => 'n3', 'capability' => 'calendar_event', 'params' => ['start' => '2027-03-17T14:00:00']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n2', 'n3'], 'inputs' => ['text' => 'Done.']],
            ],
        ];

        $out = CalendarEventSpeech::adjustPlan($plan, 'Flights on 12 Mar 06:45, 13 Mar 19:30 and 17 Mar 14:00');

        self::assertSame(['$n1.text', '$n2.text', '$n3.text'], $out['tasks'][3]['inputs']['text']);
    }

    /**
     * The planner sometimes plans one step per flight it expects. Each reads
     * the same text and the runner enters it once. Which step that is shows
     * only when they run, so the reply names all of them: a step that had
     * nothing to add returns no text.
     */
    public function testAdjustPlanNamesEveryReadingStepInTheReply(): void
    {
        $plan = $this->flightPlan();
        $plan['reply_node'] = 'n5';
        $plan['tasks'] = [
            $plan['tasks'][0],
            $plan['tasks'][1],
            ['id' => 'n3', 'capability' => 'calendar_event', 'depends_on' => ['n1'], 'inputs' => ['source' => '$n1.text']],
            ['id' => 'n4', 'capability' => 'calendar_event', 'depends_on' => ['n1'], 'inputs' => ['source' => '$n1.text']],
            ['id' => 'n5', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3', 'n4'], 'inputs' => ['text' => 'Fertig.']],
        ];

        $out = CalendarEventSpeech::adjustPlan($plan, 'Trage bitte die 3 Flüge in mein Outlook Calender');

        self::assertCount(5, $out['tasks']);
        self::assertSame(['$n2.text', '$n3.text', '$n4.text'], $out['tasks'][4]['inputs']['text']);
    }

    public function testSeveralEntriesAreConfirmedWithOneLineEach(): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $text = CalendarEventSpeech::confirmedSeveral('de', [
            ['title' => 'Flug Hamburg (HAM) → Wien (VIE)', 'start' => new \DateTimeImmutable('2030-03-12 06:45', $zone), 'end' => new \DateTimeImmutable('2030-03-12 08:20', $zone)],
            ['title' => 'Flug Wien (VIE) → Lissabon (LIS)', 'start' => new \DateTimeImmutable('2030-03-13 19:30', $zone), 'end' => new \DateTimeImmutable('2030-03-13 22:15', $zone)],
            ['title' => 'Nachtzug', 'start' => new \DateTimeImmutable('2030-03-17 23:30', $zone), 'end' => new \DateTimeImmutable('2030-03-18 06:10', $zone)],
        ], 'outlook');

        self::assertSame(
            "Ich habe 3 Kalendereinträge in Outlook hinzugefügt:\n\n"
            ."- 12.03.2030, 06:45–08:20 Uhr: Flug Hamburg (HAM) → Wien (VIE)\n"
            ."- 13.03.2030, 19:30–22:15 Uhr: Flug Wien (VIE) → Lissabon (LIS)\n"
            .'- 17.03.2030, 23:30–18.03.2030 06:10 Uhr: Nachtzug',
            $text,
        );
    }

    /**
     * Without a connected calendar the entries exist as a file. "Added"
     * would be a claim the next sentence takes back.
     */
    public function testSeveralEntriesWithoutACalendarAreCreatedNotAdded(): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $entries = [
            ['title' => 'A', 'start' => new \DateTimeImmutable('2030-03-12 06:45', $zone), 'end' => new \DateTimeImmutable('2030-03-12 08:20', $zone)],
            ['title' => 'B', 'start' => new \DateTimeImmutable('2030-03-13 19:30', $zone), 'end' => new \DateTimeImmutable('2030-03-13 22:15', $zone)],
        ];

        self::assertStringStartsWith('I created 2 calendar events:', CalendarEventSpeech::confirmedSeveral('en', $entries, ''));
        self::assertStringStartsWith('I added 2 calendar events to your calendar:', CalendarEventSpeech::confirmedSeveral('en', $entries, 'calendar'));
        self::assertStringContainsString('- 2030-03-12, 06:45–08:20: A', CalendarEventSpeech::confirmedSeveral('en', $entries, ''));
        self::assertStringContainsString('2030-03-12, 06:45–08:20 : A', CalendarEventSpeech::confirmedSeveral('fr', $entries, ''));
        self::assertStringStartsWith('They were not added to a calendar', CalendarEventSpeech::notDelivered('en', true));
        self::assertStringStartsWith('The event was not added to a calendar', CalendarEventSpeech::notDelivered('en'));
    }

    public function testNothingToEnterAsksWhichAppointmentInThePersonsLanguage(): void
    {
        self::assertStringContainsString('keinen Termin mit Datum und Uhrzeit', CalendarEventSpeech::nothingToEnter('de'));
        self::assertStringContainsString('no appointment with a date and a time', CalendarEventSpeech::nothingToEnter('en'));
        foreach (['es', 'fr', 'tr'] as $language) {
            self::assertNotSame(CalendarEventSpeech::nothingToEnter('en'), CalendarEventSpeech::nothingToEnter($language));
        }
    }

    /**
     * The canonical plan for "the flights in this picture into my calendar".
     *
     * @return array<string, mixed>
     */
    private function flightPlan(): array
    {
        return [
            'version' => 1,
            'language' => 'de',
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'file_analysis', 'inputs' => ['files' => '$message.files', 'prompt' => 'Liste jeden Flug auf.']],
                ['id' => 'n2', 'capability' => 'calendar_event', 'depends_on' => ['n1'], 'inputs' => ['source' => '$n1.text'], 'params' => ['timezone' => 'Europe/Berlin', 'channel' => 'outlook']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2'], 'inputs' => ['text' => 'Die Flüge sind eingetragen.', 'attachments' => ['$n2.file']]],
            ],
        ];
    }
}
