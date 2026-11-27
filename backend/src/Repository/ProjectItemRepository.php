<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every read of project items filters on the project and on visibility: a
 * `private` item is seen only by the member who made it.
 *
 * @extends ServiceEntityRepository<ProjectItem>
 */
final class ProjectItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectItem::class);
    }

    public function findVisible(int $projectId, string $uuid, int $viewerId): ?ProjectItem
    {
        $item = $this->visible($projectId, $viewerId)
            ->andWhere('i.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();

        return $item instanceof ProjectItem ? $item : null;
    }

    /**
     * Ordered for the board: the plan order first, then by time (a date's
     * start, a task's due time), then the oldest first.
     *
     * @return list<ProjectItem>
     */
    public function listVisible(int $projectId, int $viewerId, ?string $kind, ?string $state, bool $waitingOnly): array
    {
        $query = $this->visible($projectId, $viewerId);
        if (null !== $kind) {
            $query->andWhere('i.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $state) {
            $query->andWhere('i.state = :state')->setParameter('state', $state);
        } else {
            $query->andWhere('i.state <> :dropped')->setParameter('dropped', ProjectItem::STATE_DROPPED);
        }
        if ($waitingOnly) {
            $query->andWhere('i.waitingSince IS NOT NULL');
        }

        /** @var list<ProjectItem> $rows */
        $rows = $query
            ->addSelect('CASE WHEN i.sort IS NULL THEN 1 ELSE 0 END AS HIDDEN sortRank')
            ->addSelect('COALESCE(i.startAt, i.endAt) AS HIDDEN timeKey')
            ->addSelect('CASE WHEN COALESCE(i.startAt, i.endAt) IS NULL THEN 1 ELSE 0 END AS HIDDEN timeRank')
            ->orderBy('sortRank', 'ASC')
            ->addOrderBy('i.sort', 'ASC')
            ->addOrderBy('timeRank', 'ASC')
            ->addOrderBy('timeKey', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findInProjectById(int $projectId, int $id): ?ProjectItem
    {
        $item = $this->findOneBy(['projectId' => $projectId, 'id' => $id]);

        return $item instanceof ProjectItem ? $item : null;
    }

    /**
     * @return list<ProjectItem>
     */
    public function findChildren(int $projectId, int $parentId): array
    {
        /** @var list<ProjectItem> $rows */
        $rows = $this->findBy(['projectId' => $projectId, 'parentId' => $parentId]);

        return $rows;
    }

    public function countInProject(int $projectId): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.projectId = :project')
            ->setParameter('project', $projectId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> id => uuid, only for items of this project
     */
    public function uuidsByIds(int $projectId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: int|string, uuid: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id, i.uuid')
            ->where('i.projectId = :project')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('project', $projectId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row['uuid'];
        }

        return $map;
    }

    /**
     * Removes the items of a project and their log, for deleting the project
     * or the account that owns it.
     */
    public function deleteByProject(int $projectId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (['BPROJECTITEMLOG', 'BPROJECTITEMS'] as $table) {
            $statement = $connection->prepare('DELETE FROM '.$table.' WHERE BPROJECTID = :project');
            $statement->bindValue('project', $projectId);
            $statement->executeStatement();
        }
    }

    private function visible(int $projectId, int $viewerId): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->where('i.projectId = :project')
            ->andWhere('(i.visibility = :shared OR i.createdBy = :viewer)')
            ->setParameter('project', $projectId)
            ->setParameter('shared', ProjectItem::VISIBILITY_PROJECT)
            ->setParameter('viewer', $viewerId);
    }
}
