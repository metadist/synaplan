<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    public function save(AuditLogEntry $entry, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entry);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Newest first. `$afterId` is a cursor (exclusive) for the next page.
     *
     * @return list<AuditLogEntry>
     */
    public function listFiltered(
        ?int $actorId,
        ?string $action,
        ?string $kind,
        ?int $from,
        ?int $to,
        ?int $afterId,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('a')->orderBy('a.id', 'DESC')->setMaxResults($limit);
        if (null !== $actorId) {
            $qb->andWhere('a.actorId = :actor')->setParameter('actor', $actorId);
        }
        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $kind && '' !== $kind) {
            $qb->andWhere('a.resourceKind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $from) {
            $qb->andWhere('a.created >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('a.created <= :to')->setParameter('to', $to);
        }
        if (null !== $afterId) {
            $qb->andWhere('a.id < :after')->setParameter('after', $afterId);
        }

        /** @var list<AuditLogEntry> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function deleteOlderThan(int $createdBefore, int $batchSize = 5000): int
    {
        if ($createdBefore <= 0 || $batchSize < 1) {
            return 0;
        }

        $conn = $this->getEntityManager()->getConnection();
        $deleted = 0;
        do {
            $ids = $conn->fetchFirstColumn(
                'SELECT BID FROM BAUDITLOG WHERE BCREATED < :before ORDER BY BID ASC LIMIT '.$batchSize,
                ['before' => $createdBefore],
            );
            if ([] === $ids) {
                break;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $conn->executeStatement(
                'DELETE FROM BAUDITLOG WHERE BID IN ('.$placeholders.')',
                array_map(static fn (mixed $id): int => (int) $id, $ids),
            );
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
