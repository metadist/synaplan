<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\Message;
use App\Service\Connection\PlannerChannel;
use App\Service\File\ConnectedFolderContentGuard;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ConnectedFolderContentGuardTest extends TestCase
{
    /** @var list<PlannerChannel> */
    private array $folders;

    protected function setUp(): void
    {
        $this->folders = [
            new PlannerChannel('nextcloud', PlannerChannel::KIND_FOLDER, 'Office files', 12, ['folder_fetch', 'save_to_folder']),
        ];
    }

    public function testFolderContentAskWithoutBodiesGetsInstruction(): void
    {
        $guard = new ConnectedFolderContentGuard();
        $message = $this->message('Build a table of vendor totals from the invoices in my Nextcloud Invoices folder.');

        $instruction = $guard->systemInstructionForChatHandler(
            (string) $message->getText(),
            $this->folders,
            $message,
            [],
        );

        self::assertStringContainsString('CONNECTED FOLDER READ HONESTY', $instruction);
    }

    public function testOrdinaryChatGetsNoInstruction(): void
    {
        $guard = new ConnectedFolderContentGuard();
        $message = $this->message('What is the weather in Berlin?');

        self::assertSame('', $guard->systemInstructionForChatHandler(
            (string) $message->getText(),
            $this->folders,
            $message,
            [],
        ));
    }

    public function testSkipsWhenMessageAlreadyHasFileText(): void
    {
        $guard = new ConnectedFolderContentGuard();
        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileText')->willReturn('Invoice Nordwind 1240 EUR');
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Summarize the invoices in Nextcloud.');
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection([$file]));

        self::assertSame('', $guard->systemInstructionForChatHandler(
            (string) $message->getText(),
            $this->folders,
            $message,
            [],
        ));
    }

    private function message(string $text): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return $message;
    }
}
