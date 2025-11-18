<?php

namespace App\Repository;

use App\Entity\VerificationToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerificationToken>
 */
class VerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationToken::class);
    }

    public function findValidToken(string $token, string $type): ?VerificationToken
    {
        $verificationToken = $this->findOneBy([
            'token' => $token,
            'type' => $type,
            'used' => false,
        ]);

        if ($verificationToken && !$verificationToken->isExpired()) {
            return $verificationToken;
        }

        return null;
    }

    public function createToken(User $user, string $type, int $expiresInSeconds = 86400): VerificationToken
    {
        $token = new VerificationToken();
        $token->setUser($user);
        $token->setType($type);
        $token->setToken(bin2hex(random_bytes(32)));
        $token->setExpires(time() + $expiresInSeconds);

        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();

        return $token;
    }

    public function markAsUsed(VerificationToken $token): void
    {
        $token->setUsed(true);
        $this->getEntityManager()->flush();
    }

    public function deleteExpiredTokens(): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }
}

