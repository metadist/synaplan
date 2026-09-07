<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\File;
use App\Repository\AgentVersionRepository;
use App\Repository\FileRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\ShareRepository;
use App\Service\Agent\AgentCascadeCleanup;
use App\Service\Agent\AgentExternalCleanup;
use App\Service\Agent\AgentKnowledgeFolders;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\File\FileStorageService;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AgentCascadeCleanupTest extends TestCase
{
    private ShareRepository&MockObject $shares;
    private FileRepository&MockObject $files;
    private FileStorageService&MockObject $fileStorage;
    private VectorStorageFacade&MockObject $vectorStorage;
    private DocumentRevisionService&MockObject $revisions;
    private EntityManagerInterface&MockObject $em;
    private AgentCascadeCleanup $cleanup;

    protected function setUp(): void
    {
        $this->shares = $this->createMock(ShareRepository::class);
        $this->files = $this->createMock(FileRepository::class);
        $this->fileStorage = $this->createMock(FileStorageService::class);
        $this->vectorStorage = $this->createMock(VectorStorageFacade::class);
        $this->revisions = $this->createMock(DocumentRevisionService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $versions = $this->createMock(AgentVersionRepository::class);
        $prompts = $this->createMock(PromptRepository::class);
        $promptMeta = $this->createMock(PromptMetaRepository::class);
        $savedTasks = $this->createMock(SavedTaskRepository::class);
        $savedTaskRuns = $this->createMock(SavedTaskRunRepository::class);
        $prompts->method('find')->willReturn(null);
        $savedTasks->method('findAllByPromptAndOwner')->willReturn([]);

        $this->cleanup = new AgentCascadeCleanup(
            $this->shares,
            $versions,
            $prompts,
            $promptMeta,
            $this->files,
            $this->fileStorage,
            $this->vectorStorage,
            $this->revisions,
            $savedTasks,
            $savedTaskRuns,
            $this->em,
        );
    }

    public function testDetachDoesNotTouchFilesystemOrQdrant(): void
    {
        $agent = $this->agent();
        $file = (new File())->setFilePath('owner/nda.pdf');
        $this->files->method('findByUserAndGroupKey')->willReturn([$file]);
        $this->shares->expects(self::atLeastOnce())->method('deleteByResource');
        $this->em->expects(self::once())->method('remove')->with($file);
        $this->fileStorage->expects(self::never())->method('deleteFile');
        $this->vectorStorage->expects(self::never())->method('deleteByGroupKey');

        $pending = $this->cleanup->unshareAndRemoveDependents($agent);

        self::assertSame(4, $pending->ownerId);
        self::assertSame(AgentKnowledgeFolders::ownFolder($agent), $pending->groupKey);
        self::assertSame(['owner/nda.pdf'], $pending->filePaths);
    }

    public function testPurgeExternalDeletesVectorsAndFiles(): void
    {
        $agent = $this->agent();
        $this->files->method('findByUserAndGroupKey')->willReturn([]);
        $pending = $this->cleanup->unshareAndRemoveDependents($agent);
        $pending = new AgentExternalCleanup(
            $pending->ownerId,
            $pending->groupKey,
            ['owner/nda.pdf'],
        );

        $this->vectorStorage->expects(self::once())->method('deleteByGroupKey')->with(
            4,
            AgentKnowledgeFolders::ownFolder($agent),
        );
        $this->fileStorage->expects(self::once())->method('deleteFile')->with('owner/nda.pdf');

        $this->cleanup->purgeExternal($pending);
    }

    public function testUnshareDeletesAgentShares(): void
    {
        $this->files->method('findByUserAndGroupKey')->willReturn([]);
        $kinds = [];
        $this->shares->expects(self::atLeastOnce())->method('deleteByResource')
            ->willReturnCallback(static function (string $kind, string $id) use (&$kinds): void {
                $kinds[] = [$kind, $id];
            });

        $this->cleanup->unshareAndRemoveDependents($this->agent());

        self::assertContains([AgentKind::KEY, '7'], $kinds);
    }

    private function agent(): Agent
    {
        $agent = new Agent(4, 20, 'contract-review', 'Contract review', AgentDefinition::defaults()->toArray());
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 7);

        return $agent;
    }
}
