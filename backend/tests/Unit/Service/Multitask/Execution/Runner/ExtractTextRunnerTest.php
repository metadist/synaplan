<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Multitask\Execution\InputFileText;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\ExtractTextRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ExtractTextRunnerTest extends TestCase
{
    public function testFilesReadFromAFolderReachTheNextStepWithTheirText(): void
    {
        $result = $this->extract([
            ['local_path' => '02/inv-0101.txt', 'name' => 'inv-0101.txt', 'file_id' => 1],
            ['local_path' => '02/inv-0102.txt', 'name' => 'inv-0102.txt', 'file_id' => 2],
        ], [1 => 'ACME, 2026-10-01, 12.00', 2 => 'Globex, 2026-10-02, 30.50']);

        self::assertTrue($result->isSuccessful());
        self::assertSame("inv-0101.txt:\nACME, 2026-10-01, 12.00\n\ninv-0102.txt:\nGlobex, 2026-10-02, 30.50", $result->text);
    }

    public function testAPlanThatWiredOnlyTheFirstFileStillReadsTheWholeFolder(): void
    {
        $result = $this->extract([
            ['local_path' => '02/inv-0101.txt', 'name' => 'inv-0101.txt', 'file_id' => 1],
            ['local_path' => '02/inv-0102.txt', 'name' => 'inv-0102.txt', 'file_id' => 2],
        ], [1 => 'ACME', 2 => 'Globex'], '$n1.file');

        self::assertSame("inv-0101.txt:\nACME\n\ninv-0102.txt:\nGlobex", $result->text);
    }

    public function testAFileWithoutTextIsNamedAndTheRestIsKept(): void
    {
        $result = $this->extract([
            ['local_path' => '02/inv-0101.txt', 'name' => 'inv-0101.txt', 'file_id' => 1],
            ['local_path' => '02/scan.png', 'name' => 'scan.png', 'file_id' => 2],
        ], [1 => 'ACME, 2026-10-01, 12.00']);

        self::assertTrue($result->isSuccessful());
        self::assertStringEndsWith('No text was found in scan.png.', (string) $result->text);
    }

    public function testNoTextAnywhereSaysWhichFilesInOneSentence(): void
    {
        $result = $this->extract([
            ['local_path' => '02/scan.png', 'name' => 'scan.png', 'file_id' => 2],
            ['local_path' => '02/photo.jpg', 'name' => 'photo.jpg', 'file_id' => 3],
        ], []);

        self::assertFalse($result->isSuccessful());
        self::assertSame('Synaplan found no text in scan.png, photo.jpg.', $result->error);
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param array<int, string>         $texts text per file id
     */
    private function extract(array $files, array $texts, string $wired = '$n1.files'): NodeResult
    {
        $fileText = $this->createStub(InputFileText::class);
        $fileText->method('of')->willReturnCallback(static fn (array $descriptor): string => $texts[$descriptor['file_id'] ?? 0] ?? '');
        $message = $this->createStub(Message::class);
        $message->method('getFileText')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $context = new NodeContext($message, [], 1, ['language' => 'en']);
        $context->setResult('n1', NodeResult::ok('Read 2 files.', $files, ['folder_fetch' => true]));

        $key = '$n1.file' === $wired ? 'file' : 'files';

        return (new ExtractTextRunner($fileText))->run(new TaskNode('n2', Capability::ExtractText, ['n1'], [$key => $wired]), $context);
    }
}
