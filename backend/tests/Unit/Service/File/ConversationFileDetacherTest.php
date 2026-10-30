<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConversationFileLinkRepository;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileDetacher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConversationFileDetacherTest extends TestCase
{
    public function testDetachUnlinksTheChatAndKeepsTheFile(): void
    {
        $file = (new File())->setUserId(4)->setFilePath('4/2026/10/contract.pdf')->setMessageId(9);
        $message = (new Message())->setUserId(4)->setChatId(3)->setFilePath('/4/2026/10/contract.pdf')->setFile(1);
        $this->setId($message, 9);
        $message->addFile($file);

        $files = $this->createMock(FileRepository::class);
        $files->method('find')->with(15)->willReturn($file);

        $links = $this->createMock(ConversationFileLinkRepository::class);
        $links->method('messagesInChatTouchingFile')->with(4, 3, $file)->willReturn([$message]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $em->expects(self::never())->method('remove');

        $detached = (new ConversationFileDetacher($files, $links, $em))->detach(4, 3, 15);

        self::assertTrue($detached);
        self::assertFalse($message->getFiles()->contains($file));
        self::assertSame('', $message->getFilePath());
        self::assertSame(0, $message->getFile());
        self::assertNull($file->getMessageId());
        self::assertSame('4/2026/10/contract.pdf', $file->getFilePath());
    }

    public function testDetachDoesNothingWhenTheFileIsNotInTheChat(): void
    {
        $file = (new File())->setUserId(4)->setMessageId(9);

        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);

        $links = $this->createStub(ConversationFileLinkRepository::class);
        $links->method('messagesInChatTouchingFile')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $detached = (new ConversationFileDetacher($files, $links, $em))->detach(4, 3, 15);

        self::assertFalse($detached);
        self::assertSame(9, $file->getMessageId());
    }

    private function setId(Message $message, int $id): void
    {
        $property = new \ReflectionProperty(Message::class, 'id');
        $property->setValue($message, $id);
    }
}
