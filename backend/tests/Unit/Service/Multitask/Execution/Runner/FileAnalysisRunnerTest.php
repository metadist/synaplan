<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\FileAnalysisRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Tests\Support\StoredFileFixtures;
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

    /**
     * @param list<File> $files
     */
    private function folderReadContext(array $files): NodeContext
    {
        $context = new NodeContext($this->owner(), [], self::OWNER, ['language' => 'en']);
        $context->setResult('n1', NodeResult::ok('Read '.count($files).' files.', array_map(
            static fn (File $file): array => ['local_path' => $file->getFilePath(), 'name' => $file->getFileName(), 'file_id' => (int) $file->getId()],
            $files,
        ), ['folder_fetch' => true]));

        return $context;
    }
}
