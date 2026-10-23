<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\UnreadSourceGuard;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\DocumentGenerationRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Tests\Support\StoredFileFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentGenerationRunnerTest extends TestCase
{
    use StoredFileFixtures;

    public function testATableFromAFolderReadIsWrittenFromTheFilesText(): void
    {
        $files = [
            $this->storedFile(370, 'inv-0101.txt'),
            $this->storedFile(371, 'inv-0102.txt'),
            $this->storedFile(372, 'inv-0115.txt'),
        ];
        $fileText = $this->inputFileText($files, [
            'inv-0101.txt' => 'Vendor: Nordwind GmbH, Date: 2026-10-02, Total: 1,240.00 EUR',
            'inv-0102.txt' => 'Vendor: Ostsee Logistik, Date: 2026-10-09, Total: 386.50 EUR',
            'inv-0115.txt' => 'Vendor: Nordwind GmbH, Date: 2026-10-17, Total: 2,015.75 EUR',
        ]);
        $seen = null;
        $handler = $this->createStub(ChatHandler::class);
        $handler->method('handle')->willReturnCallback(static function (Message $message) use (&$seen): array {
            $seen = $message;

            return ['metadata' => ['generated_file' => ['path' => '02/invoices.csv', 'filename' => 'invoices.csv']]];
        });
        $context = new NodeContext($this->owner(), [], self::OWNER, ['language' => 'en']);
        $context->setResult('n1', NodeResult::ok('Read 3 files.', array_map(
            static fn (File $file): array => ['local_path' => $file->getFilePath(), 'name' => $file->getFileName(), 'file_id' => (int) $file->getId()],
            $files,
        ), ['folder_fetch' => true]));
        $runner = new DocumentGenerationRunner($handler, new NullLogger(), $this->createStub(UnreadSourceGuard::class), $fileText);

        $result = $runner->run(new TaskNode('n2', Capability::DocumentGeneration, ['n1'], [
            'prompt' => 'One table: vendor, invoice date, total, sorted by date.',
            'files' => '$n1.files',
        ]), $context);

        self::assertTrue($result->isSuccessful());
        self::assertInstanceOf(Message::class, $seen);
        self::assertCount(3, $seen->getFiles());
        self::assertStringContainsString('Ostsee Logistik, Date: 2026-10-09, Total: 386.50 EUR', $seen->getAllFilesText());
        self::assertStringContainsString('Date: 2026-10-17, Total: 2,015.75 EUR', $seen->getAllFilesText());
    }
}
