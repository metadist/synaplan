<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Connection>
 */
class ConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Connection::class);
    }

    /**
     * @return list<Connection>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndOwner(int $id, int $ownerId): ?Connection
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    /**
     * Oldest connection of a type for one owner. Re-running an OAuth consent
     * updates that row instead of leaving a trail of half-authorized copies.
     */
    public function findOneByOwnerAndType(int $ownerId, string $type): ?Connection
    {
        return $this->findOneBy(['ownerId' => $ownerId, 'type' => $type], ['id' => 'ASC']);
    }

    /**
     * Every connection of one type across all owners — the admin-side reset
     * uses this to wipe an entire connector after its app registration changed.
     *
     * @return list<Connection>
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.type = :type')
            ->setParameter('type', $type)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Connection $connection, bool $flush = true): void
    {
        $this->getEntityManager()->persist($connection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Connection $connection, bool $flush = true): void
    {
        $this->getEntityManager()->remove($connection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
