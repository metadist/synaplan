<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentRevision>
 */
class DocumentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentRevision::class);
    }

    public function latestForFile(int $fileId): ?DocumentRevision
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.fileId = :fileId')
            ->setParameter('fileId', $fileId)
            ->orderBy('r.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<DocumentRevision>
     */
    public function listForFile(int $fileId): array
    {
        /** @var list<DocumentRevision> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.fileId = :fileId')
            ->setParameter('fileId', $fileId)
            ->orderBy('r.version', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findVersion(int $fileId, int $version): ?DocumentRevision
    {
        return $this->findOneBy(['fileId' => $fileId, 'version' => $version]);
    }

    public function deleteForFile(int $fileId): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.fileId = :fileId')
            ->setParameter('fileId', $fileId)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<int> $keepVersions
     */
    public function pruneExcept(int $fileId, array $keepVersions): void
    {
        if ([] === $keepVersions) {
            $this->deleteForFile($fileId);

            return;
        }
        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.fileId = :fileId')
            ->andWhere('r.version NOT IN (:keep)')
            ->setParameter('fileId', $fileId)
            ->setParameter('keep', $keepVersions)
            ->getQuery()
            ->execute();
    }
}
