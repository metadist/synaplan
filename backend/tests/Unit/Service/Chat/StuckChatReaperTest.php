<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\Chat\StuckChatReaper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StuckChatReaperTest extends TestCase
{
    public function testReapMarksStaleRowsAndSkipsFreshCopy(): void
    {
        $staleMessage = new Message();
        $staleMessage->setStatus('processing');
        $staleMessage->setText('');
        $staleMessage->setLanguage('en');

        $partial = new Message();
        $partial->setStatus('queued');
        $partial->setText('partial answer so far');

        $extracting = new File();
        $extracting->setStatus('extracting');

        $vectorizing = new File();
        $vectorizing->setStatus('vectorizing');
        $vectorizing->setVectorState(File::VECTOR_STATE_PENDING);

        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::once())
            ->method('findStaleNonTerminal')
            ->with(self::anything(), StuckChatReaper::MESSAGE_STATUSES)
            ->willReturn([$staleMessage, $partial]);

        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())
            ->method('findStaleProcessing')
            ->with(self::anything(), StuckChatReaper::FILE_STATUSES)
            ->willReturn([$extracting, $vectorizing]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('The selected model took too long to answer. Please try again or switch to a different model.');

        $reaper = new StuckChatReaper($messages, $files, $em, $translator);
        $result = $reaper->reap(1_700_000_000);

        self::assertSame(2, $result['messages']);
        self::assertSame(2, $result['files']);
        self::assertSame('error', $staleMessage->getStatus());
        self::assertNotSame('', trim($staleMessage->getText()));
        self::assertSame('error', $partial->getStatus());
        self::assertSame('partial answer so far', $partial->getText());
        self::assertSame('error', $extracting->getStatus());
        self::assertSame('error', $vectorizing->getStatus());
        self::assertSame(File::VECTOR_STATE_FAILED, $vectorizing->getVectorState());
    }

    public function testReapIsNoopWhenNothingIsStale(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->method('findStaleNonTerminal')->willReturn([]);
        $files = $this->createMock(FileRepository::class);
        $files->method('findStaleProcessing')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $reaper = new StuckChatReaper(
            $messages,
            $files,
            $em,
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame(['messages' => 0, 'files' => 0], $reaper->reap());
    }
}
