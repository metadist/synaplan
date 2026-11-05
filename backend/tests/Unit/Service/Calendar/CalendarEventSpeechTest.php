<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\CalendarEventSpeech;
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
}
