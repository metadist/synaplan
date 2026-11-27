<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectItemLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectItemLog>
 */
final class ProjectItemLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectItemLog::class);
    }

    /**
     * @return list<ProjectItemLog> oldest first
     */
    public function forItem(int $itemId): array
    {
        /** @var list<ProjectItemLog> $rows */
        $rows = $this->findBy(['itemId' => $itemId], ['id' => 'ASC']);

        return $rows;
    }
}
