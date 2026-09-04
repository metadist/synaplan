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
     * Any OIDC row for this subject, regardless of issuer.
     */
    public function findOneOidcBySub(string $sub): ?ExternalIdentity
    {
        /** @var ExternalIdentity|null $identity */
        $identity = $this->createQueryBuilder('e')
            ->where('e.source LIKE :prefix')
            ->andWhere('e.externalId = :sub')
            ->setParameter('prefix', 'oidc:%')
            ->setParameter('sub', $sub)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $identity;
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
            $identity->setUserId($userId);
            $identity->setSource($source);
            $identity->setInstanceId($instanceId);
            $identity->setExternalId($externalId);
            $identity->setApiKeyId($apiKeyId);
            $this->getEntityManager()->persist($identity);
        }

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
}
