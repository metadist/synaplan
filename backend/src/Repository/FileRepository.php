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

    public function save(File $file, bool $flush = true): void
    {
        $this->getEntityManager()->persist($file);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array<int>                                                                                                                                  $vectorFileIds
     * @param array{search?: ?string, file_type?: ?string, date_from?: ?int, date_to?: ?int, source?: ?string, vector_state?: ?string, origin_kind?: ?string, incoming?: ?bool, sort?: ?string} $filters
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
            // §4.4: match the original (source) name too, so an Outlook
            // attachment is findable by the name the user recognises.
            $qb->andWhere('(mf.fileName LIKE :search OR mf.originalName LIKE :search OR mf.fileText LIKE :search)')
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

        $source = $filters['source'] ?? null;
        if (null !== $source && '' !== $source) {
            $sources = array_filter(array_map('trim', explode(',', $source)));
            if (count($sources) >= 1) {
                $qb->andWhere('mf.source IN (:sources)')
                    ->setParameter('sources', $sources);
            }
        }

        $vectorState = $filters['vector_state'] ?? null;
        if (null !== $vectorState && '' !== $vectorState) {
            $states = array_filter(array_map('trim', explode(',', $vectorState)));
            if (count($states) >= 1) {
                $qb->andWhere('mf.vectorState IN (:vectorStates)')
                    ->setParameter('vectorStates', $states);
            }
        }

        $incoming = $filters['incoming'] ?? null;
        if (null !== $incoming) {
            $qb->andWhere('mf.incoming = :incoming')
                ->setParameter('incoming', $incoming);
        }

        $originKind = $filters['origin_kind'] ?? null;
        if (null !== $originKind && '' !== $originKind) {
            $kinds = array_filter(array_map('trim', explode(',', $originKind)));
            if (count($kinds) >= 1) {
                $qb->andWhere('mf.originKind IN (:originKinds)')
                    ->setParameter('originKinds', $kinds);
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

        [$sortField, $sortDir] = $this->resolveSort($filters['sort'] ?? null);
        $qb->orderBy('mf.'.$sortField, $sortDir);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT mf.id)')->getQuery()->getSingleScalarResult();

        $files = $qb->select('DISTINCT mf')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['files' => $files, 'total' => $total];
    }

    /**
     * Map a client sort token to a safe (field, direction) pair. Defaults to
     * newest first; unknown tokens fall back to the default to avoid DQL
     * injection via the order-by clause.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSort(?string $sort): array
    {
        return match ($sort) {
            'name_asc' => ['fileName', 'ASC'],
            'name_desc' => ['fileName', 'DESC'],
            'size_asc' => ['fileSize', 'ASC'],
            'size_desc' => ['fileSize', 'DESC'],
            'date_asc' => ['createdAt', 'ASC'],
            default => ['createdAt', 'DESC'],
        };
    }

    /**
     * Faceted counts for the file manager filter bar + Incoming tab badge
     * (03_file-management.md §5, GET /files/facets). One grouped query per
     * dimension keeps this fast and index-friendly.
     *
     * @return array{source: array<string, int>, vector_state: array<string, int>, incoming: int, total: int}
     */
    public function getFacetsByUser(int $userId): array
    {
        $sourceRows = $this->createQueryBuilder('f')
            ->select('f.source AS k, COUNT(f.id) AS c')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('f.source')
            ->getQuery()
            ->getResult();

        $stateRows = $this->createQueryBuilder('f')
            ->select('f.vectorState AS k, COUNT(f.id) AS c')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('f.vectorState')
            ->getQuery()
            ->getResult();

        $bySource = [];
        $total = 0;
        foreach ($sourceRows as $row) {
            $count = (int) $row['c'];
            $bySource[(string) $row['k']] = $count;
            $total += $count;
        }

        $byState = [];
        foreach ($stateRows as $row) {
            $byState[(string) $row['k']] = (int) $row['c'];
        }

        $incoming = (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.userId = :userId')
            ->andWhere('f.incoming = true')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'source' => $bySource,
            'vector_state' => $byState,
            'incoming' => $incoming,
            'total' => $total,
        ];
    }

    /**
     * Find the user's files matching the given ids (for bulk triage / accept).
     *
     * @param array<int> $ids
     *
     * @return File[]
     */
    public function findByUserAndIds(int $userId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('f.userId = :userId')
            ->andWhere('f.id IN (:ids)')
            ->setParameter('userId', $userId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
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

    public function flush(): void
    {
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
