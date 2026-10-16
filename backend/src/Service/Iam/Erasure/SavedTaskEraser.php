<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 140)]
final readonly class SavedTaskEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedTaskRepository $savedTasks,
        private SavedTaskRunRepository $savedTaskRuns,
        private ShareRepository $shares,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->savedTasks->findByOwner($erasure->userId) as $task) {
            $taskId = $task->getId();
            if (null !== $taskId) {
                $this->shares->deleteByResource(SavedTaskKind::KEY, (string) $taskId);
                $this->savedTaskRuns->deleteForTask($taskId);
            }
            $this->em->remove($task);
        }
    }

    public static function covers(): array
    {
        return [SavedTask::class, SavedTaskRun::class];
    }
}
