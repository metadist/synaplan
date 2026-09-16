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
        $staleMessage->setUnixTimestamp(1_699_000_000);

        $partial = new Message();
        $partial->setStatus('queued');
        $partial->setText('partial answer so far');
        $partial->setUnixTimestamp(1_699_000_000);

        $extracting = new File();
        $extracting->setStatus('extracting');
        $extracting->setUpdatedAt(1_699_000_000);

        $vectorizing = new File();
        $vectorizing->setStatus('vectorizing');
        $vectorizing->setVectorState(File::VECTOR_STATE_PENDING);
        $vectorizing->setUpdatedAt(1_699_000_000);

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
        $em->method('contains')->willReturn(false);
        $em->expects(self::once())->method('flush');

        $translator = $this->createStub(TranslatorInterface::class);
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

    public function testReapDoesNotOverwriteARowThatFinishedAfterTheScan(): void
    {
        $completed = new Message();
        $completed->setStatus('processing');
        $completed->setText('');

        $vectorized = new File();
        $vectorized->setStatus('extracting');

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findStaleNonTerminal')->willReturn([$completed]);
        $files = $this->createStub(FileRepository::class);
        $files->method('findStaleProcessing')->willReturn([$vectorized]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->method('refresh')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Message) {
                $entity->setStatus('complete');
            }
            if ($entity instanceof File) {
                $entity->setStatus('processed');
            }
        });
        $em->expects(self::never())->method('flush');

        $reaper = new StuckChatReaper(
            $messages,
            $files,
            $em,
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame(['messages' => 0, 'files' => 0], $reaper->reap(1_700_000_000));
        self::assertSame('complete', $completed->getStatus());
        self::assertSame('processed', $vectorized->getStatus());
    }

    public function testReapDoesNotPutTimeoutCopyOnAnInboundRow(): void
    {
        $inbound = new Message();
        $inbound->setStatus('processing');
        $inbound->setDirection('IN');
        $inbound->setText('');
        $inbound->setUnixTimestamp(1_699_000_000);

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findStaleNonTerminal')->willReturn([$inbound]);
        $files = $this->createStub(FileRepository::class);
        $files->method('findStaleProcessing')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects(self::once())->method('flush');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $reaper = new StuckChatReaper($messages, $files, $em, $translator);
        $result = $reaper->reap(1_700_000_000);

        self::assertSame(1, $result['messages']);
        self::assertSame('error', $inbound->getStatus());
        self::assertSame('', $inbound->getText());
    }

    public function testReapIsNoopWhenNothingIsStale(): void
    {
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findStaleNonTerminal')->willReturn([]);
        $files = $this->createStub(FileRepository::class);
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
