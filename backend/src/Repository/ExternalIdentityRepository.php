<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalIdentity>
 */
class ExternalIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalIdentity::class);
    }

    public function save(ExternalIdentity $identity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($identity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByTriple(string $source, string $instanceId, string $externalId): ?ExternalIdentity
    {
        return $this->findOneBy([
            'source' => $source,
            'instanceId' => $instanceId,
            'externalId' => $externalId,
        ]);
    }

    /**
     * @return list<ExternalIdentity>
     */
    public function findByUserId(int $userId): array
    {
        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['userId' => $userId], ['created' => 'ASC']);

        return $rows;
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<ExternalIdentity>
     */
    public function findByUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->createQueryBuilder('e')
            ->where('e.userId IN (:ids)')
            ->setParameter('ids', $userIds)
            ->orderBy('e.created', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Insert or update the (source, instanceId, externalId) row and bump lastSeen.
     *
     * The row is keyed by the external identity, not by the Synaplan user, so
     * an existing row is re-owned: the caller has just proven that this
     * external identity belongs to `$userId` now. Leaving the old owner behind
     * would let them disconnect the link and revoke the new owner's key, while
     * the new owner would not see the link at all.
     */
    public function upsert(
        int $userId,
        string $source,
        string $externalId,
        string $instanceId = '',
        ?int $apiKeyId = null,
    ): ExternalIdentity {
        $identity = $this->findOneByTriple($source, $instanceId, $externalId);
        if (null === $identity) {
            $identity = new ExternalIdentity();
            $identity->setSource($source);
            $identity->setInstanceId($instanceId);
            $identity->setExternalId($externalId);
            $identity->setApiKeyId($apiKeyId);
            $this->getEntityManager()->persist($identity);
        }

        $identity->setUserId($userId);
        $identity->touchLastSeen();
        if (null !== $apiKeyId) {
            $identity->setApiKeyId($apiKeyId);
        }

        $this->getEntityManager()->flush();

        return $identity;
    }

    public function deleteByUserId(int $userId): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<ExternalIdentity>
     */
    public function findByInstanceId(string $instanceId): array
    {
        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['instanceId' => $instanceId]);

        return $rows;
    }

    /**
     * @param list<int> $apiKeyIds
     *
     * @return list<ExternalIdentity>
     */
    public function findByApiKeyIds(array $apiKeyIds): array
    {
        if ([] === $apiKeyIds) {
            return [];
        }

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['apiKeyId' => $apiKeyIds]);

        return $rows;
    }

    public function findOneByApiKeyId(int $apiKeyId): ?ExternalIdentity
    {
        $row = $this->findOneBy(['apiKeyId' => $apiKeyId]);

        return $row instanceof ExternalIdentity ? $row : null;
    }

    public function remove(ExternalIdentity $identity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($identity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
