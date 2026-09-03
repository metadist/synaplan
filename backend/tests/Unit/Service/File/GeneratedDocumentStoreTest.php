<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\Message;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GeneratedDocumentStoreTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/gen-doc-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->uploadDir);
    }

    public function testStoresSourceWithoutPdfWhenEngineOff(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);
        $converter->expects(self::never())->method('convert');

        $bundle = $this->store($converter)->store(
            ['filename' => 'report.docx', 'content' => '# Title', 'extension' => 'docx', 'export' => 'pdf'],
            $this->message(),
        );

        self::assertNotNull($bundle);
        self::assertSame('report.docx', $bundle->source->getFileName());
        self::assertNull($bundle->export);
        self::assertSame($bundle->source, $bundle->primary());
        self::assertCount(1, $bundle->files());
    }

    public function testAttachesPdfWhenEngineOnAndExportRequested(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->method('convert')->willReturnCallback(function (string $source): string {
            $pdf = dirname($source).'/tmp-export.pdf';
            file_put_contents($pdf, '%PDF-ok');

            return $pdf;
        });

        $bundle = $this->store($converter)->store(
            ['filename' => 'report.docx', 'content' => '# Title', 'extension' => 'docx', 'export' => 'pdf'],
            $this->message(),
        );

        self::assertNotNull($bundle);
        self::assertNotNull($bundle->export);
        self::assertSame('report.pdf', $bundle->export->getFileName());
        self::assertSame('pdf', $bundle->export->getFileType());
        self::assertSame($bundle->export, $bundle->primary());
        self::assertCount(2, $bundle->files());
        self::assertSame('# Title', $bundle->export->getFileText());
    }

    public function testKeepsSourceWhenConvertFails(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->method('convert')->willReturn(null);

        $bundle = $this->store($converter)->store(
            ['filename' => 'report.docx', 'content' => '# Title', 'extension' => 'docx', 'export' => 'pdf'],
            $this->message(),
        );

        self::assertNotNull($bundle);
        self::assertNull($bundle->export);
        self::assertSame('report.docx', $bundle->primary()->getFileName());
    }

    public function testConversationWantsPdfExportDetectsAskAndPriorFile(): void
    {
        self::assertTrue(GeneratedDocumentStore::conversationWantsPdfExport([
            'Create a PDF with a Friday agenda',
        ]));
        self::assertTrue(GeneratedDocumentStore::conversationWantsPdfExport([
            'Erstelle mir ein PDF mit einer Agenda',
        ]));
        self::assertTrue(GeneratedDocumentStore::conversationWantsPdfExport([
            'Füge ein Kapitel hinzu',
            '__FILE_GENERATED__:sicherheitsrichtlinie.pdf',
        ]));
        self::assertFalse(GeneratedDocumentStore::conversationWantsPdfExport([
            'Create a Word letter for new employees',
        ]));
        self::assertFalse(GeneratedDocumentStore::conversationWantsPdfExport([
            'Can you make PDFs?',
        ]));
    }

    public function testInheritsPdfExportWhenCurrentMessageAsksAndModelOmitsBexport(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->method('convert')->willReturnCallback(function (string $source): string {
            $pdf = dirname($source).'/tmp-export.pdf';
            file_put_contents($pdf, '%PDF-ok');

            return $pdf;
        });

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Create a PDF agenda');
        $message->method('getChatId')->willReturn(null);

        $bundle = $this->store($converter)->store(
            ['filename' => 'agenda.docx', 'content' => '# Agenda', 'extension' => 'docx'],
            $message,
        );

        self::assertNotNull($bundle);
        self::assertNotNull($bundle->export);
        self::assertSame('agenda.pdf', $bundle->export->getFileName());
    }

    public function testRefusesEmptyContent(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);

        self::assertNull($this->store($converter)->store(
            ['filename' => 'report.docx', 'content' => '   ', 'extension' => 'docx'],
            $this->message(),
        ));
    }

    private function store(OfficeConverterClient $converter): GeneratedDocumentStore
    {
        $generator = $this->createMock(DocumentGeneratorService::class);
        $generator->method('write')->willReturnCallback(static function (string $content, string $ext, string $path): void {
            file_put_contents($path, 'BIN-'.$ext.'-'.$content);
        });

        $resolver = $this->createMock(DocumentImageReferenceResolver::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $content): array => [
            'content' => $content,
            'images' => [],
        ]);

        $paths = $this->createMock(UserUploadPathBuilder::class);
        $paths->method('buildUserBaseRelativePath')->willReturn('u');

        $em = $this->createMock(EntityManagerInterface::class);

        return new GeneratedDocumentStore(
            $generator,
            $resolver,
            $paths,
            $em,
            $converter,
            new NullLogger(),
            $this->uploadDir,
        );
    }

    private function message(): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('write a report');

        return $message;
    }
}
