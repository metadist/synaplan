<?php

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Findet einen aktiven API-Key.
     */
    public function findActiveByKey(string $key): ?ApiKey
    {
        return $this->createQueryBuilder('a')
            ->where('a.key = :key')
            ->andWhere('a.status = :status')
            ->setParameter('key', $key)
            ->setParameter('status', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Findet alle API-Keys eines Owners.
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('a.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Speichert einen API-Key.
     */
    public function save(ApiKey $apiKey, bool $flush = true): void
    {
        $this->getEntityManager()->persist($apiKey);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht einen API-Key.
     */
    public function remove(ApiKey $apiKey, bool $flush = true): void
    {
        $this->getEntityManager()->remove($apiKey);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
