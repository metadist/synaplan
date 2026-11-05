<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiLiteracyAck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiLiteracyAck>
 */
class AiLiteracyAckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiLiteracyAck::class);
    }

    public function findFor(int $userId, string $version): ?AiLiteracyAck
    {
        $row = $this->findOneBy(['userId' => $userId, 'version' => $version]);

        return $row instanceof AiLiteracyAck ? $row : null;
    }

    public function countConfirmed(string $version): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.version = :version')
            ->andWhere('a.confirmedAt IS NOT NULL')
            ->setParameter('version', $version)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, bool> user id => confirmed this version
     */
    public function confirmedMap(array $userIds, string $version): array
    {
        $map = [];
        foreach ($userIds as $id) {
            $map[$id] = false;
        }
        if ([] === $userIds) {
            return $map;
        }

        $rows = $this->createQueryBuilder('a')
            ->where('a.version = :version')
            ->andWhere('a.userId IN (:ids)')
            ->andWhere('a.confirmedAt IS NOT NULL')
            ->setParameter('version', $version)
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $map[$row->getUserId()] = true;
        }

        return $map;
    }

    public function save(AiLiteracyAck $ack): void
    {
        $this->getEntityManager()->persist($ack);
        $this->getEntityManager()->flush();
    }
}
