<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's projects. Their sources are ordinary file rows of the account
 * and go with the file eraser.
 */
#[AsTaggedItem(priority: 100)]
final readonly class ProjectEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->projects->findBy(['userId' => $erasure->userId]) as $project) {
            $this->em->remove($project);
        }
    }

    public static function covers(): array
    {
        return [Project::class];
    }
}
