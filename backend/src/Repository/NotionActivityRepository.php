<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotionActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotionActivity>
 */
class NotionActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotionActivity::class);
    }

    public function save(NotionActivity $activity): void
    {
        $this->getEntityManager()->persist($activity);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<NotionActivity>
     */
    public function recent(int $userId, int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.created', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function lastTitle(int $userId, string $pageId): ?string
    {
        $row = $this->createQueryBuilder('a')
            ->select('a.title')
            ->where('a.userId = :userId')
            ->andWhere('a.pageId = :pageId')
            ->andWhere("a.title <> ''")
            ->setParameter('userId', $userId)
            ->setParameter('pageId', $pageId)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($row) && is_string($row['title'] ?? null) ? $row['title'] : null;
    }

    public function deleteByUser(int $userId): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
