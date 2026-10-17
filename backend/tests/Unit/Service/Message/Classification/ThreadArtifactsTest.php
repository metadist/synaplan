<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Classification\ThreadArtifacts;
use PHPUnit\Framework\TestCase;

final class ThreadArtifactsTest extends TestCase
{
    public function testOnlyTheLatestAssistantTurnCountsAsTheOneJustMade(): void
    {
        $thread = [$this->turn('OUT', '__FILE_GENERATED__:report.docx'), $this->turn('IN', 'thanks'), $this->turn('OUT', 'You are welcome!')];

        self::assertFalse(ThreadArtifacts::lastAssistantTurnIs($thread, ThreadArtifacts::isGeneratedFileMessage(...)));
        self::assertTrue(ThreadArtifacts::anyAssistantTurnIs($thread, ThreadArtifacts::isGeneratedFileMessage(...)));
        self::assertFalse(ThreadArtifacts::lastAssistantTurnIs([], ThreadArtifacts::isGeneratedFileMessage(...)));
    }

    public function testAPictureOrAVideoIsGeneratedMediaAndADocumentIsNot(): void
    {
        self::assertSame(
            [true, true, false, false],
            [
                ThreadArtifacts::isGeneratedMediaMessage($this->turn('OUT', '__IMAGE_GENERATED__', '7/2026/10/1_openai.png')),
                ThreadArtifacts::isGeneratedMediaMessage($this->turn('OUT', '__VIDEO_GENERATED__', '7/2026/10/1_google.mp4')),
                ThreadArtifacts::isGeneratedMediaMessage($this->turn('OUT', '__FILE_GENERATED__:a.docx', '7/a.docx')),
                ThreadArtifacts::isGeneratedMediaMessage($this->turn('OUT', 'plain answer')),
            ],
        );
    }

    public function testReferencesToADocumentOrAPictureAreRecognised(): void
    {
        self::assertSame(
            [true, true, false, true, true, false],
            [
                ThreadArtifacts::mentionsDocumentReference('ändere den Titel in der Datei'),
                ThreadArtifacts::mentionsDocumentReference('add a column to the sheet'),
                ThreadArtifacts::mentionsDocumentReference('how are you'),
                ThreadArtifacts::mentionsMediaReference('und jetzt die Farbe ändern'),
                ThreadArtifacts::mentionsMediaReference('crop the photo'),
                ThreadArtifacts::mentionsMediaReference('tell me a joke'),
            ],
        );
    }

    private function turn(string $direction, string $text, string $filePath = ''): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getText')->willReturn($text);
        $message->method('getFilePath')->willReturn($filePath);

        return $message;
    }
}
