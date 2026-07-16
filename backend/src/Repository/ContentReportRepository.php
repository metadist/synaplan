<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentReport>
 */
class ContentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentReport::class);
    }

    public function save(ContentReport $report, bool $flush = true): void
    {
        $this->getEntityManager()->persist($report);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return ContentReport[]
     */
    public function findFiltered(?string $status, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $status && '' !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(?string $status): int
    {
        $qb = $this->createQueryBuilder('r')->select('COUNT(r.id)');

        if (null !== $status && '' !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Whether this reporter already has an open report for the same content
     * (prevents duplicate spam of the operator inbox).
     */
    public function existsOpenForContent(int $reporterId, string $contentType, int $contentId): bool
    {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.reporterId = :reporterId')
            ->andWhere('r.contentType = :contentType')
            ->andWhere('r.contentId = :contentId')
            ->andWhere('r.status = :status')
            ->setParameter('reporterId', $reporterId)
            ->setParameter('contentType', $contentType)
            ->setParameter('contentId', $contentId)
            ->setParameter('status', ContentReport::STATUS_OPEN)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
