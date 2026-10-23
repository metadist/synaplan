<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\CodeRunInputFiles;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class CodeRunInputFilesTest extends TestCase
{
    public function testIdsThePlanNamedWin(): void
    {
        $node = new TaskNode('n2', Capability::CodeRun, ['n1'], [], ['inputFileIds' => [5, '6']]);

        self::assertSame([5, 6], $this->ids($node, $this->folderRead([11, 12])));
    }

    public function testAPlanThatWiredOnlyTheFirstFileStillMountsTheWholeRead(): void
    {
        $node = new TaskNode('n2', Capability::CodeRun, ['n1']);

        $ids = $this->ids($node, $this->folderRead([11, 12]), ['file' => ['local_path' => '02/f11.csv', 'file_id' => 11]]);

        self::assertSame([21, 11, 12], $ids);
    }

    public function testTheFilesAFolderReadProducedAreMountedNextToTheUploads(): void
    {
        $node = new TaskNode('n2', Capability::CodeRun, ['n1']);

        self::assertSame([21, 11, 12], $this->ids($node, $this->folderRead([11, 12])));
    }

    public function testFilesOfOtherStepsAreNotMountedUnlessWired(): void
    {
        $context = $this->context(attachments: [21]);
        $context->setResult('n1', NodeResult::ok('Done.', [['local_path' => '02/out.csv', 'file_id' => 31]]));
        $node = new TaskNode('n2', Capability::CodeRun, ['n1']);

        self::assertSame([21], (new CodeRunInputFiles($this->createStub(FileRepository::class)))->ids($node, $context, [], 1));
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return list<int>
     */
    private function ids(TaskNode $node, NodeContext $context, array $inputs = []): array
    {
        return (new CodeRunInputFiles($this->createStub(FileRepository::class)))->ids($node, $context, $inputs, 1);
    }

    /**
     * @param list<int> $fileIds
     */
    private function folderRead(array $fileIds): NodeContext
    {
        $context = $this->context(attachments: [21]);
        $context->setResult('n1', NodeResult::ok('Read files.', array_map(
            static fn (int $id): array => ['local_path' => '02/f'.$id.'.csv', 'name' => 'f'.$id.'.csv', 'file_id' => $id],
            $fileIds,
        ), ['folder_fetch' => true]));

        return $context;
    }

    /**
     * @param list<int> $attachments
     */
    private function context(array $attachments): NodeContext
    {
        $files = new ArrayCollection();
        foreach ($attachments as $id) {
            $file = new File();
            (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);
            $files->add($file);
        }
        $message = $this->createStub(Message::class);
        $message->method('getFiles')->willReturn($files);

        return new NodeContext($message, [], 1, ['language' => 'en']);
    }
}
