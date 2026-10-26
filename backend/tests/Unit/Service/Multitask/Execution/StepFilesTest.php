<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\StepFiles;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use PHPUnit\Framework\TestCase;

final class StepFilesTest extends TestCase
{
    public function testWiredFilesComeFirstThenTheRestOfTheFolderReadOnce(): void
    {
        $context = $this->context();
        $context->setResult('n1', NodeResult::ok('Read 2 files.', [
            ['local_path' => '02/a.pdf', 'file_id' => 1],
            ['local_path' => '02/b.pdf', 'file_id' => 2],
        ], ['folder_fetch' => true]));
        $node = new TaskNode('n2', Capability::Summarize, ['n1']);

        $files = StepFiles::of($node, $context, ['file' => ['local_path' => '02/b.pdf', 'file_id' => 2]]);

        self::assertSame([2, 1], StepFiles::storedIds($files));
    }

    public function testOnlyFolderReadsHandOverFilesUnwired(): void
    {
        $context = $this->context();
        $context->setResult('n1', NodeResult::ok('Done.', [['local_path' => '02/out.csv', 'file_id' => 9]]));
        $context->setResult('n2', NodeResult::failed('Synaplan could not reach files.example.org. Nothing was read.', ['folder_fetch' => true]));

        self::assertSame([], StepFiles::of(new TaskNode('n3', Capability::ExtractText, ['n1', 'n2']), $context, []));
    }

    public function testInstructionsAndCodeAreNeverTakenForFiles(): void
    {
        $files = StepFiles::of(new TaskNode('n1', Capability::CodeRun), $this->context(), [
            'prompt' => ['path' => 'looks/like/a/file.txt'],
            'script' => ['path' => 'also/not.py'],
            'files' => [['path' => '/api/v1/files/uploads/02/c.txt', 'type' => 'document']],
        ]);

        self::assertSame([['path' => '/api/v1/files/uploads/02/c.txt', 'type' => 'document']], $files);
        self::assertSame([], StepFiles::storedIds($files));
    }

    private function context(): NodeContext
    {
        return new NodeContext($this->createStub(Message::class), [], 1, ['language' => 'en']);
    }
}
