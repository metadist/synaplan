<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\Office\DocumentCombineException;
use App\Service\File\Office\DocumentCombineService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\DocumentCombineRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillCatalog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * #1694: merge attached office/PDF files through DocumentCombineService
 * instead of answering analyzefile prose that invents a filename.
 */
final class DocumentCombineRunnerTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/document-combine-runner-'.bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testMergesAttachedFilesWithTheCombineServiceAndPicksNoModel(): void
    {
        $xlsx = $this->file(101, 'Finanzmodell.xlsx');
        $pdf = $this->file(102, 'Finanzmodell.pdf');
        $message = (new Message())->setUserId(7)->setDirection('IN')
            ->setText('führe beide dateien in eine pdf zusammen')
            ->addFile($xlsx)
            ->addFile($pdf);

        $combined = $this->file(104, 'u/2026/09/Finanzmodell_combined.pdf');
        $combined->setFileName('Finanzmodell_combined.pdf');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->with(7)->willReturn($user);

        $combine = $this->createMock(DocumentCombineService::class);
        $combine->expects(self::once())
            ->method('combineToPdf')
            ->with($user, [101, 102], null)
            ->willReturn($combined);

        $result = $this->runner($combine, $this->repository($xlsx, $pdf), $users)
            ->run(new TaskNode('n1', Capability::DocumentCombine), $this->context($message));

        self::assertTrue($result->isSuccessful());
        self::assertSame('Combined PDF created: Finanzmodell_combined.pdf', $result->text);
        self::assertSame([[
            'path' => '/api/v1/files/uploads/u/2026/09/Finanzmodell_combined.pdf',
            'type' => 'document',
            'local_path' => 'u/2026/09/Finanzmodell_combined.pdf',
        ]], $result->files);
        self::assertSame([101, 102], $result->metadata['document_combine']['source_file_ids']);
        self::assertSame(104, $result->metadata['document_combine']['pdf_file_id']);
    }

    public function testFailsHonestlyWithFewerThanTwoCombinableFiles(): void
    {
        $message = (new Message())->setUserId(7)->setDirection('IN')->setText('führe beide zusammen');

        $combine = $this->createMock(DocumentCombineService::class);
        $combine->expects(self::never())->method('combineToPdf');

        $result = $this->runner($combine, $this->repository(), $this->users())
            ->run(new TaskNode('n1', Capability::DocumentCombine), $this->context($message));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('at least two', (string) $result->error);
    }

    public function testSurfacesCombineServiceErrors(): void
    {
        $xlsx = $this->file(101, 'a.xlsx');
        $pdf = $this->file(102, 'b.pdf');
        $message = (new Message())->setUserId(7)->setDirection('IN')
            ->setText('merge into one pdf')
            ->addFile($xlsx)
            ->addFile($pdf);

        $combine = $this->createMock(DocumentCombineService::class);
        $combine->method('combineToPdf')
            ->willThrowException(new DocumentCombineException('engine_required', 'Combining office documents needs the office engine', 503));

        $result = $this->runner($combine, $this->repository($xlsx, $pdf), $this->users())
            ->run(new TaskNode('n1', Capability::DocumentCombine), $this->context($message));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('office engine', (string) $result->error);
    }

    public function testCapabilityIsOfferedToThePlannerOnlyWhenTheServiceIsWired(): void
    {
        $unwired = (new \ReflectionClass(DocumentCombineRunner::class))->newInstanceWithoutConstructor();
        $wired = $this->runner(
            $this->createMock(DocumentCombineService::class),
            $this->repository(),
            $this->users(),
        );

        $without = (new SkillCatalog([$unwired]))->renderCapabilityList();
        $with = (new SkillCatalog([$wired]))->renderCapabilityList();

        self::assertStringNotContainsString('"document_combine": Merge', $without);
        self::assertStringContainsString('- "document_combine": Merge two or more office/PDF files', $with);
        self::assertStringContainsString('file-chip Combine action', $with);
    }

    private function runner(
        DocumentCombineService $combine,
        FileRepository&MockObject $repository,
        UserRepository $users,
        array $threadFiles = [],
    ): DocumentCombineRunner {
        $repository->method('findFilesByMessageIds')->willReturn($threadFiles);

        return new DocumentCombineRunner(
            $combine,
            new ConversationFileCatalog($repository, $this->uploadDir),
            $repository,
            $users,
            new NullLogger(),
        );
    }

    private function context(Message $message): NodeContext
    {
        return new NodeContext($message, [], 7, ['language' => 'de']);
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

    private function users(): UserRepository
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        return $users;
    }

    private function file(int $id, string $name): File
    {
        file_put_contents($this->uploadDir.'/'.basename($name), 'content');

        $file = (new File())
            ->setUserId(7)
            ->setFilePath($name)
            ->setFileType(pathinfo($name, PATHINFO_EXTENSION))
            ->setFileName(basename($name))
            ->setFileSize(7)
            ->setFileMime('application/octet-stream')
            ->setStatus('processed');
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}
