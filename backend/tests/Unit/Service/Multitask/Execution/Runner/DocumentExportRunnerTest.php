<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\Office\DocumentExportService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\DocumentExportRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillCatalog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * #1691: "export this file as PDF" converts the attached original with the
 * office engine instead of re-authoring a new document from its text extract.
 */
final class DocumentExportRunnerTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/document-export-runner-'.bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testConvertsTheAttachedWorkbookWithTheExportServiceAndPicksNoModel(): void
    {
        $workbook = $this->file(98, 'Finanzmodell.xlsx', 'planned revenue');
        $message = (new Message())->setUserId(7)->setDirection('IN')->setText('hieraus eine pdf')->addFile($workbook);

        $pdf = $this->file(102, 'Finanzmodell_1.pdf', 'planned revenue');
        $export = $this->createMock(DocumentExportService::class);
        $export->method('isEnabled')->willReturn(true);
        $export->expects(self::once())->method('exportToPdf')->with($workbook)->willReturn($this->uploadDir.'/Finanzmodell.export.sheet.pdf');

        $store = $this->createMock(GeneratedDocumentStore::class);
        $store->expects(self::once())->method('storePdfFor')
            ->with($workbook, $this->uploadDir.'/Finanzmodell.export.sheet.pdf', 'planned revenue', false)
            ->willReturn($pdf);

        $result = $this->runner($export, $store, $this->repository($workbook))
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context($message));

        self::assertTrue($result->isSuccessful());
        self::assertSame('PDF created from "Finanzmodell.xlsx": Finanzmodell_1.pdf', $result->text);
        self::assertSame([[
            'path' => '/api/v1/files/uploads/Finanzmodell_1.pdf',
            'type' => 'document',
            'local_path' => 'Finanzmodell_1.pdf',
        ]], $result->files);
        self::assertSame(98, $result->metadata['document_export']['source_file_id']);
        self::assertSame(102, $result->metadata['document_export']['pdf_file_id']);
    }

    public function testConvertsTheFileThePlannerNamedFromTheThread(): void
    {
        $brief = $this->file(40, 'brief.docx', 'the brief', messageId: 300);
        $deck = $this->file(41, 'deck.pptx', 'the deck', messageId: 310);
        $message = (new Message())->setUserId(7)->setDirection('IN')->setText('die docx als pdf');

        $export = $this->createMock(DocumentExportService::class);
        $export->method('isEnabled')->willReturn(true);
        $export->expects(self::once())->method('exportToPdf')->with($brief)->willReturn($this->uploadDir.'/brief.export.pdf');

        $store = $this->createMock(GeneratedDocumentStore::class);
        $store->method('storePdfFor')->willReturn($this->file(42, 'brief_1.pdf', 'the brief'));

        $node = new TaskNode('n1', Capability::DocumentExport, [], [], ['file' => 'file:40']);
        $result = $this->runner($export, $store, $this->repository($brief, $deck), [$deck, $brief])
            ->run($node, $this->context($message, [$this->threadMessage(300), $this->threadMessage(310)]));

        self::assertTrue($result->isSuccessful());
    }

    public function testDefaultsToTheNewestOfficeFileOfTheThreadAndSkipsPdfsAndImages(): void
    {
        $photo = $this->file(50, 'photo.png', '', messageId: 330);
        $existingPdf = $this->file(51, 'scan.pdf', 'scan', messageId: 320);
        $sheet = $this->file(52, 'sheet.ods', 'cells', messageId: 310);
        $message = (new Message())->setUserId(7)->setDirection('IN')->setText('als pdf bitte');

        $export = $this->createMock(DocumentExportService::class);
        $export->method('isEnabled')->willReturn(true);
        $export->expects(self::once())->method('exportToPdf')->with($sheet)->willReturn($this->uploadDir.'/sheet.export.sheet.pdf');

        $store = $this->createMock(GeneratedDocumentStore::class);
        $store->method('storePdfFor')->willReturn($this->file(53, 'sheet_1.pdf', 'cells'));

        $result = $this->runner($export, $store, $this->repository($photo, $existingPdf, $sheet), [$photo, $existingPdf, $sheet])
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context($message, [
                $this->threadMessage(310), $this->threadMessage(320), $this->threadMessage(330),
            ]));

        self::assertTrue($result->isSuccessful());
    }

    public function testFailsHonestlyWithoutAnOfficeFileOrWhenTheEngineDeclines(): void
    {
        $message = (new Message())->setUserId(7)->setDirection('IN')->setText('hieraus eine pdf');

        $export = $this->createMock(DocumentExportService::class);
        $export->method('isEnabled')->willReturn(true);
        $export->expects(self::never())->method('exportToPdf');
        $store = $this->createMock(GeneratedDocumentStore::class);
        $store->expects(self::never())->method('storePdfFor');

        $result = $this->runner($export, $store, $this->repository())
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context($message));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no office file', (string) $result->error);

        $workbook = $this->file(98, 'book.xlsx', 'x');
        $declining = $this->createMock(DocumentExportService::class);
        $declining->method('isEnabled')->willReturn(true);
        $declining->method('exportToPdf')->willReturn(null);

        $result = $this->runner($declining, $store, $this->repository($workbook))
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context($message->addFile($workbook)));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('could not convert "book.xlsx"', (string) $result->error);
    }

    /**
     * Both reference branches enforce ownership — an attachment without an id
     * is referenced as `attached:N` and must be checked just like `file:ID`.
     */
    public function testAttachmentOfAnotherUserIsRejected(): void
    {
        $foreign = $this->file(null, 'foreign.xlsx', 'x', userId: 8);
        $message = (new Message())->setUserId(7)->setDirection('IN')
            ->setText('hieraus eine pdf')
            ->addFile($foreign);

        $export = $this->createMock(DocumentExportService::class);
        $export->method('isEnabled')->willReturn(true);
        $export->expects(self::never())->method('exportToPdf');

        $result = $this->runner($export, $this->createMock(GeneratedDocumentStore::class), $this->repository())
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context($message));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('foreign.xlsx', (string) $result->error);
    }

    public function testCapabilityIsOfferedToThePlannerOnlyWhenTheEngineIsConfigured(): void
    {
        $off = $this->createMock(DocumentExportService::class);
        $off->method('isEnabled')->willReturn(false);
        $on = $this->createMock(DocumentExportService::class);
        $on->method('isEnabled')->willReturn(true);
        $store = $this->createMock(GeneratedDocumentStore::class);

        $withoutEngine = (new SkillCatalog([$this->runner($off, $store, $this->repository())]))->renderCapabilityList();
        $withEngine = (new SkillCatalog([$this->runner($on, $store, $this->repository())]))->renderCapabilityList();

        self::assertStringNotContainsString('"document_export": Convert', $withoutEngine);
        self::assertStringContainsString('- "document_export": Convert an office file that ALREADY EXISTS', $withEngine);
        self::assertStringContainsString('NEVER plan extract_text + document_generation', $withEngine);

        $result = $this->runner($off, $store, $this->repository())
            ->run(new TaskNode('n1', Capability::DocumentExport), $this->context((new Message())->setUserId(7)));
        self::assertFalse($result->isSuccessful());
    }

    /**
     * @param list<File> $threadFiles
     */
    private function runner(DocumentExportService $export, GeneratedDocumentStore $store, FileRepository&MockObject $repository, array $threadFiles = []): DocumentExportRunner
    {
        $repository->method('findFilesByMessageIds')->willReturn($threadFiles);

        return new DocumentExportRunner(
            $export,
            $store,
            new ConversationFileCatalog($repository, $this->uploadDir),
            $repository,
            new NullLogger(),
        );
    }

    /**
     * @param array<int, Message> $thread
     */
    private function context(Message $message, array $thread = []): NodeContext
    {
        return new NodeContext($message, $thread, 7, ['language' => 'de']);
    }

    private function repository(File ...$files): FileRepository&MockObject
    {
        $byId = [];
        foreach ($files as $file) {
            $byId[$file->getId()] = $file;
        }

        $repository = $this->createMock(FileRepository::class);
        $repository->method('find')->willReturnCallback(static fn (mixed $id): ?File => $byId[(int) $id] ?? null);
        $repository->method('findOneBy')->willReturn(null);

        return $repository;
    }

    private function threadMessage(int $id): Message
    {
        $message = (new Message())->setUserId(7);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function file(?int $id, string $name, string $text, ?int $messageId = null, int $userId = 7): File
    {
        file_put_contents($this->uploadDir.'/'.$name, 'content');

        $file = (new File())
            ->setUserId($userId)
            ->setFilePath($name)
            ->setFileType(pathinfo($name, PATHINFO_EXTENSION))
            ->setFileName($name)
            ->setFileSize(7)
            ->setFileMime('application/octet-stream')
            ->setFileText($text)
            ->setStatus('processed');
        $file->setMessageId($messageId);
        if (null !== $id) {
            (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);
        }

        return $file;
    }
}
