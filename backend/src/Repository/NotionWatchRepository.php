<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotionWatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotionWatch>
 */
class NotionWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotionWatch::class);
    }

    public function save(NotionWatch $watch): void
    {
        $this->getEntityManager()->persist($watch);
        $this->getEntityManager()->flush();
    }

    public function remove(NotionWatch $watch): void
    {
        $this->getEntityManager()->remove($watch);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<NotionWatch>
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('w.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForUser(int $id, int $userId): ?NotionWatch
    {
        return $this->findOneBy(['id' => $id, 'userId' => $userId]);
    }

    public function findOneByPage(int $userId, string $pageId): ?NotionWatch
    {
        return $this->findOneBy(['userId' => $userId, 'pageId' => $pageId]);
    }

    /**
     * @return list<NotionWatch>
     */
    public function findDue(int $now, int $limit): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.enabled = true')
            ->andWhere('w.nextCheckAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('w.nextCheckAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function deleteByUser(int $userId): void
    {
        $this->createQueryBuilder('w')
            ->delete()
            ->where('w.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
