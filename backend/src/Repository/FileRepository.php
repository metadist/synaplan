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
     * @return array{files: File[], total: int}
     */
    public function findByUserPaginated(
        int $userId,
        ?string $groupKey,
        int $offset,
        int $limit,
        array $vectorFileIds = [],
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
