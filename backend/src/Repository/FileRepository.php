<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<File>
 */
class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /**
     * @param array<int>                                                                     $vectorFileIds
     * @param array{search?: ?string, file_type?: ?string, date_from?: ?int, date_to?: ?int} $filters
     *
     * @return array{files: File[], total: int}
     */
    public function findByUserPaginated(
        int $userId,
        ?string $groupKey,
        int $offset,
        int $limit,
        array $vectorFileIds = [],
        array $filters = [],
    ): array {
        $qb = $this->createQueryBuilder('mf')
            ->where('mf.userId = :userId')
            ->setParameter('userId', $userId);

        if ($groupKey) {
            if (!empty($vectorFileIds)) {
                $qb->andWhere('(mf.groupKey = :groupKey OR mf.id IN (:vectorFileIds))')
                    ->setParameter('groupKey', $groupKey)
                    ->setParameter('vectorFileIds', $vectorFileIds);
            } else {
                $qb->andWhere('mf.groupKey = :groupKey')
                    ->setParameter('groupKey', $groupKey);
            }
        }

        $search = $filters['search'] ?? null;
        if (null !== $search && '' !== $search) {
            $qb->andWhere('(mf.fileName LIKE :search OR mf.fileText LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        $fileType = $filters['file_type'] ?? null;
        if (null !== $fileType && '' !== $fileType) {
            $types = array_filter(array_map('trim', explode(',', $fileType)));
            if (1 === count($types)) {
                $qb->andWhere('mf.fileType = :fileType')
                    ->setParameter('fileType', $types[0]);
            } elseif (count($types) > 1) {
                $qb->andWhere('mf.fileType IN (:fileTypes)')
                    ->setParameter('fileTypes', $types);
            }
        }

        $dateFrom = $filters['date_from'] ?? null;
        if (null !== $dateFrom) {
            $qb->andWhere('mf.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        $dateTo = $filters['date_to'] ?? null;
        if (null !== $dateTo) {
            $qb->andWhere('mf.createdAt <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        $qb->orderBy('mf.createdAt', 'DESC');

        $total = (int) (clone $qb)->select('COUNT(DISTINCT mf.id)')->getQuery()->getSingleScalarResult();

        $files = $qb->select('DISTINCT mf')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['files' => $files, 'total' => $total];
    }

    /**
     * @return array<string, int> group name => file count
     */
    public function getGroupCountsByUser(int $userId): array
    {
        $qb = $this->createQueryBuilder('f')
            ->select('f.groupKey AS name, COUNT(f.id) AS cnt')
            ->where('f.userId = :userId')
            ->andWhere('f.groupKey IS NOT NULL')
            ->andWhere("f.groupKey != ''")
            ->andWhere("f.groupKey != 'DEFAULT'")
            ->setParameter('userId', $userId)
            ->groupBy('f.groupKey')
            ->orderBy('f.groupKey', 'ASC');

        $groups = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $groups[$row['name']] = (int) $row['cnt'];
        }

        return $groups;
    }

    public function delete(File $file): void
    {
        $this->getEntityManager()->remove($file);
        $this->getEntityManager()->flush();
    }

    public function updateGroupKey(File $file, string $groupKey): void
    {
        $file->setGroupKey($groupKey);
        $this->getEntityManager()->flush();
    }

    /**
     * Backfill missing groupKey values from vector store data.
     *
     * @param File[]             $files          files to check
     * @param array<int, string> $vectorGroupMap file ID => group key
     */
    public function backfillGroupKeys(array $files, array $vectorGroupMap): void
    {
        $changed = false;
        foreach ($files as $file) {
            $resolvedKey = $vectorGroupMap[$file->getId()] ?? null;
            if ($resolvedKey && null === $file->getGroupKey()) {
                $file->setGroupKey($resolvedKey);
                $changed = true;
            }
        }

        if ($changed) {
            $this->getEntityManager()->flush();
        }
    }
}
