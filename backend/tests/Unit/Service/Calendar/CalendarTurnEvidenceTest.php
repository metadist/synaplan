<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Calendar\CalendarTurnEvidence;
use App\Service\Message\SpokenInput;
use PHPUnit\Framework\TestCase;

/**
 * What a turn offers as proof of an event's time: the latest message, what
 * the person wrote earlier, and the text of the files they attached.
 */
final class CalendarTurnEvidenceTest extends TestCase
{
    public function testAClockTimeInTheLatestMessageBacksAnyStart(): void
    {
        $evidence = CalendarTurnEvidence::ofTurn($this->message('Sync tomorrow at 15:00'), []);

        self::assertTrue($evidence->backsStart('2026-06-10T15:00:00'));
        self::assertFalse($evidence->hasFileText());
    }

    public function testADateAloneBacksNoStart(): void
    {
        $evidence = CalendarTurnEvidence::ofTurn($this->message('termin morgen in den kalender'), []);

        self::assertFalse($evidence->backsStart('2026-06-10T09:00:00'));
        self::assertFalse($evidence->backsStart(''));
    }

    /**
     * "Termin um 15 Uhr" — "an welchem Tag?" — "morgen". The hour was named
     * one message earlier; asking for it again is the ping-pong.
     */
    public function testAnEarlierMessageBacksTheHourItNamed(): void
    {
        $thread = [
            $this->message('Trag einen Termin um 15 Uhr ein', id: 1),
            $this->message('An welchem Tag?', direction: 'OUT', id: 2),
        ];
        $evidence = CalendarTurnEvidence::ofTurn($this->message('morgen', id: 3), $thread);

        self::assertTrue($evidence->backsStart('2026-06-10T15:00:00'));
        self::assertFalse($evidence->backsStart('2026-06-10T09:00:00'), 'only the hour that was named');
    }

    /**
     * A reply of the assistant is not the person's word: its own question
     * carries an example time ("zum Beispiel morgen um 15 Uhr").
     */
    public function testAnAssistantReplyBacksNothing(): void
    {
        $thread = [
            $this->message('termin morgen', id: 1),
            $this->message('Um welche Uhrzeit? Zum Beispiel „morgen um 15 Uhr“.', direction: 'OUT', id: 2),
        ];
        $evidence = CalendarTurnEvidence::ofTurn($this->message('mach das', id: 3), $thread);

        self::assertFalse($evidence->backsStart('2026-06-10T15:00:00'));
    }

    public function testFilesOfTheMessageAreItsFileText(): void
    {
        $message = $this->message('Trage bitte die 3 Flüge in mein Outlook Calender');
        $message->addFile($this->file('06:45 Hamburg → 08:20 Wien'));

        $evidence = CalendarTurnEvidence::ofTurn($message, []);

        self::assertTrue($evidence->hasFileText());
        self::assertStringContainsString('06:45', $evidence->fileText);
        self::assertFalse($evidence->backsStart('2027-03-12T06:45:00'), 'a file is read, it does not back a planned start');
    }

    /**
     * "Es steht alles im Bild" has no attachment of its own. The picture of
     * the earlier message is what it refers to.
     */
    public function testAFollowUpFallsBackToFilesAttachedEarlier(): void
    {
        $first = $this->message('Trage bitte die 3 Flüge in mein Outlook Calender', id: 1);
        $first->addFile($this->file('06:45 Hamburg → 08:20 Wien'));

        $evidence = CalendarTurnEvidence::ofTurn(
            $this->message('Es steht alles im Bild', id: 3),
            [$first, $this->message('Could you please provide the dates?', direction: 'OUT', id: 2)],
        );

        self::assertStringContainsString('06:45', $evidence->fileText);
    }

    public function testFilesOfThisMessageWinOverEarlierOnes(): void
    {
        $first = $this->message('alt', id: 1);
        $first->addFile($this->file('old booking 09:15'));
        $current = $this->message('neu', id: 2);
        $current->addFile($this->file('new booking 11:45'));

        $evidence = CalendarTurnEvidence::ofTurn($current, [$first]);

        self::assertStringContainsString('11:45', $evidence->fileText);
        self::assertStringNotContainsString('09:15', $evidence->fileText);
    }

    /**
     * A voice note is the person speaking. Its transcript is the message,
     * not a document that could hold an appointment.
     */
    public function testAVoiceNoteIsNotAFile(): void
    {
        $message = $this->message('termin morgen in den kalender');
        SpokenInput::mark($message);
        $message->addFile($this->file('termin morgen in den kalender', 'webm', 'voice.webm'));

        self::assertFalse(CalendarTurnEvidence::ofTurn($message, [])->hasFileText());
    }

    public function testThreadRowsWithoutEntitiesCount(): void
    {
        $evidence = CalendarTurnEvidence::ofTurn($this->message('morgen'), [
            ['role' => 'user', 'content' => 'Meeting at 3 pm'],
            ['role' => 'assistant', 'content' => 'Which day? For example at 9:00.'],
        ]);

        self::assertTrue($evidence->backsStart('2026-06-10T15:00:00'));
        self::assertFalse($evidence->backsStart('2026-06-10T09:00:00'));
    }

    private function message(string $text, string $direction = 'IN', ?int $id = null): Message
    {
        $message = new Message();
        $message->setText($text);
        $message->setDirection($direction);
        $message->setFile(0);
        if (null !== $id) {
            (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);
        }

        return $message;
    }

    private function file(string $text, string $type = 'png', string $name = 'booking.png'): File
    {
        return (new File())
            ->setFileName($name)
            ->setFileType($type)
            ->setFileText($text);
    }
}
