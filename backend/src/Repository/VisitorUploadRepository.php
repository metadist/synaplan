<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Files a widget visitor uploaded. Such a file is bound to the visitor's
 * widget session (`BUSERSESSIONID`); no other file carries a session id.
 *
 * @extends ServiceEntityRepository<File>
 */
final class VisitorUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /**
     * @param list<int> $fileIds
     *
     * @return list<int> the ids among them that are visitor uploads
     */
    public function idsAmong(array $fileIds): array
    {
        if ([] === $fileIds) {
            return [];
        }

        $ids = $this->createQueryBuilder('f')
            ->select('f.id')
            ->where('f.id IN (:ids)')
            ->andWhere('f.userSessionId IS NOT NULL')
            ->setParameter('ids', $fileIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * Visitor uploads in id order, one page at a time.
     *
     * @return list<File>
     */
    public function pageAfter(int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.userSessionId IS NOT NULL')
            ->andWhere('f.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
