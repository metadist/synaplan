<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UrlWatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UrlWatch>
 */
class UrlWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UrlWatch::class);
    }

    /**
     * @return list<UrlWatch>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('w.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForOwner(int $id, int $ownerId): ?UrlWatch
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    public function findOneByOwnerAndHash(int $ownerId, string $urlHash): ?UrlWatch
    {
        return $this->findOneBy(['ownerId' => $ownerId, 'urlHash' => $urlHash]);
    }
}
