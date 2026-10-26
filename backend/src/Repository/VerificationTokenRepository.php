<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\VerificationToken;
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

    /**
     * Like {@see createToken()}, but only the SHA-256 of the secret is stored.
     * Returns the secret, which goes into the link and nowhere else.
     */
    public function createHashedToken(User $user, string $type, int $expiresInSeconds): string
    {
        $secret = bin2hex(random_bytes(32));
        $token = new VerificationToken();
        $token->setUser($user);
        $token->setType($type);
        $token->setToken(hash('sha256', $secret));
        $token->setExpires(time() + $expiresInSeconds);

        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();

        return $secret;
    }

    public function findValidHashedToken(string $secret, string $type): ?VerificationToken
    {
        return '' === $secret ? null : $this->findValidToken(hash('sha256', $secret), $type);
    }

    /**
     * Marks the token used if nobody did first. Two requests with the same
     * link race on this one row; exactly one of them gets true.
     */
    public function claimUnused(VerificationToken $token): bool
    {
        $changed = $this->createQueryBuilder('t')
            ->update()
            ->set('t.used', ':used')
            ->where('t.id = :id')
            ->andWhere('t.used = :unused')
            ->setParameter('used', true)
            ->setParameter('unused', false)
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();

        return 1 === $changed;
    }

    /**
     * Drops every token of one type for an account, e.g. sign-in links once
     * the address they were sent to is no longer the account's address.
     */
    public function deleteForUser(int $userId, string $type): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.userId = :userId')
            ->andWhere('t.type = :type')
            ->setParameter('userId', $userId)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
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
