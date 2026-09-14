<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComputeWorkspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComputeWorkspace>
 */
class ComputeWorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComputeWorkspace::class);
    }

    public function findActiveForUser(int $userId): ?ComputeWorkspace
    {
        $found = $this->findOneBy([
            'userId' => $userId,
            'status' => ComputeWorkspace::STATUS_ACTIVE,
        ]);

        return $found instanceof ComputeWorkspace ? $found : null;
    }

    public function save(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->persist($workspace);
        $this->getEntityManager()->flush();
    }

    public function remove(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->remove($workspace);
        $this->getEntityManager()->flush();
    }
}
