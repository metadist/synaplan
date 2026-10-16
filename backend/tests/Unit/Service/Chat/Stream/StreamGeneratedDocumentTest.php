<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Chat\Stream\StreamGeneratedFiles;
use App\Service\File\GeneratedFileWriter;
use App\Service\Media\GeneratedFileRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A streamed document envelope becomes a file through the same
 * GeneratedFileWriter the buffered chat uses — except whitespace-only
 * content, which the stream refuses up front (#1196) so no blank file and
 * no empty row are left behind.
 */
final class StreamGeneratedDocumentTest extends TestCase
{
    public function testWhitespaceOnlyDocumentIsRefused(): void
    {
        $writer = $this->createMock(GeneratedFileWriter::class);
        $writer->expects(self::never())->method('store');

        $bundle = $this->files($writer)->storeDocument(
            ['filename' => 'notes.docx', 'content' => "  \n\t ", 'extension' => 'docx'],
            new Message(),
        );

        self::assertNull($bundle);
    }

    public function testADocumentIsWrittenByTheSharedWriter(): void
    {
        $fileData = ['filename' => 'notes.docx', 'content' => '# Notes', 'extension' => 'docx'];
        $message = new Message();
        $file = new File();
        $writer = $this->createMock(GeneratedFileWriter::class);
        $writer->expects(self::once())->method('store')->with($fileData, $message, true)->willReturn($file);

        $bundle = $this->files($writer)->storeDocument($fileData, $message, true);

        self::assertNotNull($bundle);
        self::assertSame($file, $bundle->primary());
    }

    public function testAFailedWriteIsNoBundle(): void
    {
        $writer = $this->createStub(GeneratedFileWriter::class);
        $writer->method('store')->willReturn(null);

        self::assertNull($this->files($writer)->storeDocument(
            ['filename' => 'notes.docx', 'content' => '# Notes', 'extension' => 'docx'],
            new Message(),
        ));
    }

    private function files(GeneratedFileWriter $writer): StreamGeneratedFiles
    {
        return new StreamGeneratedFiles(
            $writer,
            $this->createStub(GeneratedFileRegistrar::class),
            new NullLogger(),
        );
    }
}
