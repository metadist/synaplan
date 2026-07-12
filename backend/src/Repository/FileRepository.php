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
     * @param array<int>                                                                                                                                                                        $vectorFileIds
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
            // Incognito-session files never surface in the file manager.
            ->andWhere('mf.ephemeral = false')
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
            ->andWhere('f.ephemeral = false')
            ->setParameter('userId', $userId)
            ->groupBy('f.source')
            ->getQuery()
            ->getResult();

        $stateRows = $this->createQueryBuilder('f')
            ->select('f.vectorState AS k, COUNT(f.id) AS c')
            ->where('f.userId = :userId')
            ->andWhere('f.ephemeral = false')
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
            ->andWhere('f.ephemeral = false')
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
     * Resolve the existing file to replace for a CORE-4 overwrite upload.
     *
     * Primary match is the stable external identity (user, source, source_id);
     * when the source carries no id we fall back to (user, group_key,
     * original_name) so a re-sync of the same document still overwrites in place
     * instead of duplicating. Returns the most recent match, or null when this
     * is genuinely a new file.
     */
    public function findForOverwrite(
        int $userId,
        string $source,
        ?string $sourceId,
        ?string $groupKey,
        ?string $originalName,
    ): ?File {
        if (null !== $sourceId && '' !== trim($sourceId)) {
            return $this->createQueryBuilder('f')
                ->where('f.userId = :userId')
                ->andWhere('f.source = :source')
                ->andWhere('f.sourceId = :sourceId')
                ->setParameter('userId', $userId)
                ->setParameter('source', $source)
                ->setParameter('sourceId', trim($sourceId))
                ->orderBy('f.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if (null !== $originalName && '' !== trim($originalName)) {
            $qb = $this->createQueryBuilder('f')
                ->where('f.userId = :userId')
                ->andWhere('f.source = :source')
                ->andWhere('f.originalName = :originalName')
                ->setParameter('userId', $userId)
                ->setParameter('source', $source)
                ->setParameter('originalName', trim($originalName));

            if (null !== $groupKey && '' !== $groupKey) {
                $qb->andWhere('f.groupKey = :groupKey')->setParameter('groupKey', $groupKey);
            }

            return $qb->orderBy('f.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }

        return null;
    }

    /**
     * Load a user's files for the given external source ids, keyed by source id,
     * for the CORE-4 bulk stale check. When several rows share a source id (a
     * pre-overwrite duplicate) the most recent wins.
     *
     * @param array<int, string> $sourceIds
     *
     * @return array<string, File>
     */
    public function findByUserSourceIds(int $userId, string $source, array $sourceIds): array
    {
        $sourceIds = array_values(array_filter(array_map('trim', $sourceIds), static fn (string $s): bool => '' !== $s));
        if ([] === $sourceIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->where('f.userId = :userId')
            ->andWhere('f.source = :source')
            ->andWhere('f.sourceId IN (:sourceIds)')
            ->setParameter('userId', $userId)
            ->setParameter('source', $source)
            ->setParameter('sourceIds', $sourceIds)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $file) {
            $sid = $file->getSourceId();
            if (null !== $sid) {
                $map[$sid] = $file;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int> group name => file count
     */
    public function getGroupCountsByUser(int $userId): array
    {
        $qb = $this->createQueryBuilder('f')
            ->select('f.groupKey AS name, COUNT(f.id) AS cnt')
            ->where('f.userId = :userId')
            ->andWhere('f.ephemeral = false')
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

    /**
     * Ephemeral (incognito-session) files older than the given cutoff — the
     * reaper deletes them from disk and DB as a safety net for sessions the
     * frontend could not clean up (tab crash, network loss).
     *
     * @return File[]
     */
    public function findExpiredEphemeral(int $cutoffTimestamp, int $limit = 500): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.ephemeral = true')
            ->andWhere('f.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoffTimestamp)
            ->orderBy('f.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
