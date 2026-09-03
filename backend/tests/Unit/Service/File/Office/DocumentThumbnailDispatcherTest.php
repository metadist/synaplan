<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Entity\File;
use App\Message\GenerateDocumentThumbnailMessage;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\Office\OfficeConverterClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DocumentThumbnailDispatcherTest extends TestCase
{
    public function testDispatchesPdfEvenWhenEngineOff(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (GenerateDocumentThumbnailMessage $m): bool => 9 === $m->fileId))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);

        $file = $this->file(9, 'report.pdf', 'pdf');
        (new DocumentThumbnailDispatcher($bus, $converter))->dispatchIfNeeded($file);
    }

    public function testSkipsOfficeWhenEngineOff(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);

        $file = $this->file(3, 'brief.docx', 'docx');
        (new DocumentThumbnailDispatcher($bus, $converter))->dispatchIfNeeded($file);
    }

    public function testDispatchesOfficeWhenEngineOn(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);

        $file = $this->file(3, 'brief.docx', 'docx');
        (new DocumentThumbnailDispatcher($bus, $converter))->dispatchIfNeeded($file);
    }

    private function file(int $id, string $name, string $type): File
    {
        $file = new File();
        $file->setUserId(1);
        $file->setFilePath('u/'.$name);
        $file->setFileName($name);
        $file->setFileType($type);
        $file->setFileSize(10);
        $file->setFileMime('application/octet-stream');

        $ref = new \ReflectionProperty(File::class, 'id');
        $ref->setValue($file, $id);

        return $file;
    }
}
