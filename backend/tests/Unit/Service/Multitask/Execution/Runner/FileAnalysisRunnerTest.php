<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\FileAnalysisRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Tests\Support\StoredFileFixtures;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileAnalysisRunnerTest extends TestCase
{
    use StoredFileFixtures;

    public function testEveryFileReadFromAFolderIsAnalyzedEvenWhenThePlanWiredOnlyTheFirst(): void
    {
        $invoice = $this->storedFile(11, 'inv-0101.pdf');
        $scan = $this->storedFile(12, 'scan.png');
        $seen = null;
        $handler = $this->createStub(FileAnalysisHandler::class);
        $handler->method('handle')->willReturnCallback(static function (Message $message) use (&$seen): array {
            $seen = $message;

            return ['content' => 'Two files: an invoice from ACME and a scan.', 'metadata' => []];
        });
        $context = $this->folderReadContext([$invoice, $scan]);
        $runner = new FileAnalysisRunner($handler, new NullLogger(), fileText: $this->inputFileText([$invoice, $scan], ['inv-0101.pdf' => 'ACME, 12.00']));

        $result = $runner->run(new TaskNode('n2', Capability::FileAnalysis, ['n1'], ['file' => '$n1.file']), $context);

        self::assertTrue($result->isSuccessful());
        self::assertInstanceOf(Message::class, $seen);
        self::assertSame([$invoice, $scan], $seen->getFiles()->toArray());
        self::assertSame('ACME, 12.00', $invoice->getFileText());
        self::assertSame('', $scan->getFileText());
    }

    public function testAnUploadedReferenceIsComparedWithTheFilesReadFromAFolder(): void
    {
        $reference = $this->storedFile(5, 'price-list.pdf');
        $reference->setFileText('ACME: 12.00');
        $invoice = $this->storedFile(11, 'inv-0101.pdf');
        $seen = null;
        $handler = $this->createStub(FileAnalysisHandler::class);
        $handler->method('handle')->willReturnCallback(static function (Message $message) use (&$seen): array {
            $seen = $message;

            return ['content' => 'The invoice matches the price list.', 'metadata' => []];
        });
        $context = $this->folderReadContext([$invoice], [$reference]);
        $runner = new FileAnalysisRunner($handler, new NullLogger(), fileText: $this->inputFileText([$invoice], ['inv-0101.pdf' => 'ACME, 12.00']));

        $runner->run(new TaskNode('n2', Capability::FileAnalysis, ['n1'], ['files' => '$n1.files']), $context);

        self::assertInstanceOf(Message::class, $seen);
        self::assertSame([$reference, $invoice], $seen->getFiles()->toArray());
    }

    public function testALegacySingleUploadStaysNextToTheFolderFiles(): void
    {
        $legacy = $this->storedFile(4, 'voice-note.pdf');
        $invoice = $this->storedFile(11, 'inv-0101.pdf');
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(self::OWNER);
        $message->method('getId')->willReturn(99);
        $message->method('getText')->willReturn('check the invoice against my note');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getFile')->willReturn(1);
        $message->method('getFilePath')->willReturn('02/voice-note.pdf');
        $repository = $this->createStub(FileRepository::class);
        $repository->method('findFilesByMessageIds')->willReturn([$legacy]);
        $seen = null;
        $handler = $this->createStub(FileAnalysisHandler::class);
        $handler->method('handle')->willReturnCallback(static function (Message $message) use (&$seen): array {
            $seen = $message;

            return ['content' => 'Done.', 'metadata' => []];
        });
        $context = new NodeContext($message, [], self::OWNER, ['language' => 'en']);
        $context->setResult('n1', NodeResult::ok('Read 1 file.', [['local_path' => '02/inv-0101.pdf', 'file_id' => 11]], ['folder_fetch' => true]));
        $runner = new FileAnalysisRunner($handler, new NullLogger(), fileRepository: $repository, fileText: $this->inputFileText([$invoice], []));

        $runner->run(new TaskNode('n2', Capability::FileAnalysis, ['n1']), $context);

        self::assertInstanceOf(Message::class, $seen);
        self::assertSame([$legacy, $invoice], $seen->getFiles()->toArray());
    }

    /**
     * @param list<File> $files
     * @param list<File> $uploads
     */
    private function folderReadContext(array $files, array $uploads = []): NodeContext
    {
        $context = new NodeContext($this->owner(uploads: $uploads), [], self::OWNER, ['language' => 'en']);
        $context->setResult('n1', NodeResult::ok('Read '.count($files).' files.', array_map(
            static fn (File $file): array => ['local_path' => $file->getFilePath(), 'name' => $file->getFileName(), 'file_id' => (int) $file->getId()],
            $files,
        ), ['folder_fetch' => true]));

        return $context;
    }
}
