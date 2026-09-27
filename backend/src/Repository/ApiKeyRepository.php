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
    /** Skip repeat writes when the key was already seen within this window. */
    private const LAST_USED_MIN_INTERVAL_SECONDS = 60;

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
     * Record that this key authenticated a request.
     *
     * The write is its own statement, so it is stored even when the request
     * never flushes the entity manager. A key already seen in the last minute
     * is left unchanged.
     */
    public function touchLastUsed(ApiKey $apiKey): void
    {
        $id = $apiKey->getId();
        if (null === $id) {
            return;
        }

        $now = time();
        $previous = $apiKey->getLastUsed();
        if ($previous > 0 && ($now - $previous) < self::LAST_USED_MIN_INTERVAL_SECONDS) {
            return;
        }

        $apiKey->setLastUsed($now);
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BAPIKEYS SET BLASTUSED = :now WHERE BID = :id',
            [
                'now' => $now,
                'id' => $id,
            ],
        );
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
