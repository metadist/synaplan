<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestSession>
 */
class GuestSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestSession::class);
    }

    public function save(GuestSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->persist($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findBySessionId(string $sessionId): ?GuestSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    public function countActiveSessionsByIp(string $ip): int
    {
        return (int) $this->createQueryBuilder('gs')
            ->select('COUNT(gs.id)')
            ->where('gs.ipAddress = :ip')
            ->andWhere('gs.expires > :now')
            ->setParameter('ip', $ip)
            ->setParameter('now', time())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpiredSessions(): int
    {
        return $this->createQueryBuilder('gs')
            ->delete()
            ->where('gs.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }
}
