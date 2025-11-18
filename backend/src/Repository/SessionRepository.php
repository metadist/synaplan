<?php

namespace App\Repository;

use App\Entity\Session;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Session>
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    /**
     * Findet Session by Token
     */
    public function findByToken(string $token): ?Session
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Findet aktive Session (nicht expired)
     */
    public function findActiveByToken(string $token): ?Session
    {
        return $this->createQueryBuilder('s')
            ->where('s.token = :token')
            ->andWhere('s.expires > :now')
            ->setParameter('token', $token)
            ->setParameter('now', time())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Löscht abgelaufene Sessions
     */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }

    /**
     * Speichert eine Session
     */
    public function save(Session $session, bool $flush = true): void
    {
        $this->getEntityManager()->persist($session);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht eine Session
     */
    public function remove(Session $session, bool $flush = true): void
    {
        $this->getEntityManager()->remove($session);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

