<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Repository\AgentVersionRepository;
use App\Repository\FileRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\ShareRepository;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\File\FileStorageService;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ResourceKind\AssistantKind;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes everything that belongs to an assistant before the BAGENTS row
 * itself is deleted: shares, versions, the instruction prompt, the own
 * knowledge folder (files + folder shares), and saved tasks bound to that
 * prompt. Filesystem and Qdrant deletes are returned as
 * {@see AgentExternalCleanup} and must run after the caller commits.
 */
final readonly class AgentCascadeCleanup
{
    public function __construct(
        private ShareRepository $shares,
        private AgentVersionRepository $versions,
        private PromptRepository $prompts,
        private PromptMetaRepository $promptMeta,
        private FileRepository $files,
        private FileStorageService $fileStorage,
        private VectorStorageFacade $vectorStorage,
        private DocumentRevisionService $documentRevisions,
        private SavedTaskRepository $savedTasks,
        private SavedTaskRunRepository $savedTaskRuns,
        private EntityManagerInterface $em,
    ) {
    }

    public function unshareAndRemoveDependents(Agent $agent): AgentExternalCleanup
    {
        $id = $agent->getId();
        if (null !== $id) {
            $this->shares->deleteByResource(AgentKind::KEY, (string) $id);
            $this->versions->deleteForAgent($id);
        }

        $external = $this->detachOwnKnowledge($agent);
        $this->removeBoundSavedTasks($agent);
        $this->removeInstructionPrompt($agent);

        return $external;
    }

    public function purgeExternal(AgentExternalCleanup $cleanup): void
    {
        $this->vectorStorage->deleteByGroupKey($cleanup->ownerId, $cleanup->groupKey);
        foreach ($cleanup->filePaths as $path) {
            $this->fileStorage->deleteFile($path);
        }
    }

    private function detachOwnKnowledge(Agent $agent): AgentExternalCleanup
    {
        $ownerId = $agent->getOwnerId();
        $groupKey = AgentKnowledgeFolders::ownFolder($agent);
        $this->shares->deleteByResource(
            KnowledgeFolderKind::KEY,
            KnowledgeFolderKind::resourceId($ownerId, $groupKey),
        );

        $filePaths = [];
        foreach ($this->files->findByUserAndGroupKey($ownerId, $groupKey) as $file) {
            $this->documentRevisions->deleteForFile($file);
            $path = $file->getFilePath();
            if ('' !== $path) {
                $filePaths[] = $path;
            }
            $this->em->remove($file);
        }
        $this->em->flush();

        return new AgentExternalCleanup($ownerId, $groupKey, $filePaths);
    }

    private function removeBoundSavedTasks(Agent $agent): void
    {
        $tasks = $this->savedTasks->findAllByPromptAndOwner(
            $agent->getPromptId(),
            $agent->getOwnerId(),
        );
        foreach ($tasks as $task) {
            $taskId = $task->getId();
            if (null !== $taskId) {
                $this->shares->deleteByResource(SavedTaskKind::KEY, (string) $taskId);
                $this->savedTaskRuns->deleteForTask($taskId);
            }
            $this->em->remove($task);
        }
        $this->em->flush();
    }

    private function removeInstructionPrompt(Agent $agent): void
    {
        $prompt = $this->prompts->find($agent->getPromptId());
        if (!$prompt instanceof Prompt) {
            return;
        }
        if (!AssistantKind::belongsToAgent($prompt) || $prompt->getOwnerId() !== $agent->getOwnerId()) {
            return;
        }

        $promptId = $prompt->getId();
        if (null !== $promptId) {
            $this->shares->deleteByResource(AssistantKind::KEY, (string) $promptId);
            foreach ($this->promptMeta->findByPrompt($promptId) as $meta) {
                $this->em->remove($meta);
            }
        }
        $this->em->remove($prompt);
        $this->em->flush();
    }
}
