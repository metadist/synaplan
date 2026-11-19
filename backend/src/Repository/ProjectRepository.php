<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
final class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @return list<Project>
     */
    public function findByOwner(int $userId): array
    {
        /** @var list<Project> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.userId = :user')
            ->setParameter('user', $userId)
            // Pinned projects first (the API promises that order), then the most recently changed.
            ->addSelect('CASE WHEN p.pinnedAt IS NULL THEN 1 ELSE 0 END AS HIDDEN pinRank')
            ->orderBy('pinRank', 'ASC')
            ->addOrderBy('p.updated', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOwned(string $uuid, int $userId): ?Project
    {
        $project = $this->findOneBy(['uuid' => $uuid, 'userId' => $userId]);

        return $project instanceof Project ? $project : null;
    }

    public function findById(int $id): ?Project
    {
        $project = $this->find($id);

        return $project instanceof Project ? $project : null;
    }

    public function countByOwner(int $userId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
