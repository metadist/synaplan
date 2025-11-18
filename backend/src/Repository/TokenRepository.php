<?php

namespace App\Repository;

use App\Entity\Token;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Token>
 */
class TokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Token::class);
    }

    /**
     * Findet einen gültigen Token (nicht expired, nicht used)
     */
    public function findValidToken(string $token, string $type): ?Token
    {
        return $this->createQueryBuilder('t')
            ->where('t.token = :token')
            ->andWhere('t.type = :type')
            ->andWhere('t.used = false')
            ->andWhere('t.expires > :now')
            ->setParameter('token', $token)
            ->setParameter('type', $type)
            ->setParameter('now', time())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Löscht abgelaufene Tokens
     */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expires < :now')
            ->setParameter('now', time() - 86400) // Keep for 1 day after expiry
            ->getQuery()
            ->execute();
    }

    /**
     * Speichert einen Token
     */
    public function save(Token $token, bool $flush = true): void
    {
        $this->getEntityManager()->persist($token);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht einen Token
     */
    public function remove(Token $token, bool $flush = true): void
    {
        $this->getEntityManager()->remove($token);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

