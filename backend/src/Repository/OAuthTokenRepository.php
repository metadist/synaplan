<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthToken>
 */
class OAuthTokenRepository extends ServiceEntityRepository
{
    private const PURGE_BATCH = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthToken::class);
    }

    public function save(OAuthToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function isRevoked(string $id): bool
    {
        $token = $this->find($id);

        return !$token instanceof OAuthToken || $token->isRevoked() || $token->getExpires() < time();
    }

    public function revoke(string $id): void
    {
        $token = $this->find($id);
        if ($token instanceof OAuthToken && !$token->isRevoked()) {
            $token->revoke();
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Lets a live token end at $until instead of when it was going to; it
     * never lives longer. Unlike revoke(), the token still counts as live
     * until then, so revoking it or its grant ends it at once.
     */
    public function retire(string $id, int $until): void
    {
        $token = $this->find($id);
        if ($token instanceof OAuthToken && !$token->isRevoked() && $token->getExpires() > $until) {
            $token->expireAt($until);
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Revokes every token a client holds for a person, or for nobody in
     * particular when $userId is null. With $externalId, only the grant for
     * that one cloud account. Returns how many were live.
     */
    public function revokeAll(string $clientId, ?int $userId, ?string $externalId = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->update()
            ->set('t.revoked', ':yes')
            ->where('t.clientId = :client')
            ->andWhere('t.revoked = :no')
            ->andWhere('t.kind IN (:kinds)')
            ->setParameter('yes', true)
            ->setParameter('no', false)
            ->setParameter('client', $clientId)
            ->setParameter('kinds', [OAuthToken::KIND_ACCESS, OAuthToken::KIND_REFRESH, OAuthToken::KIND_CODE]);
        if (null !== $userId) {
            $qb->andWhere('t.userId = :user')->setParameter('user', $userId);
        }
        if (null !== $externalId) {
            $qb->andWhere('t.externalId = :external')->setParameter('external', $externalId);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Ends every grant a person holds with any cloud. Signing out everywhere
     * and changing the password both come through here, so a cloud that was
     * allowed before cannot keep acting afterwards.
     */
    public function revokeAllForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.revoked', ':yes')
            ->where('t.userId = :user')
            ->andWhere('t.revoked = :no')
            ->andWhere('t.kind IN (:kinds)')
            ->setParameter('yes', true)
            ->setParameter('no', false)
            ->setParameter('user', $userId)
            ->setParameter('kinds', [OAuthToken::KIND_ACCESS, OAuthToken::KIND_REFRESH, OAuthToken::KIND_CODE])
            ->getQuery()
            ->execute();
    }

    public function revokeForAccount(string $clientId, int $userId, string $externalId): int
    {
        return $this->revokeAll($clientId, $userId, $externalId);
    }

    /**
     * Records a client assertion's jti. False when it was seen before, so a
     * replayed assertion is refused; the primary key makes this atomic.
     */
    public function claimAssertion(string $clientId, string $jti, int $expires): bool
    {
        $id = 'a:'.hash('sha256', $clientId."\0".$jti);
        $connection = $this->getEntityManager()->getConnection();
        try {
            $connection->insert('BOAUTHTOKENS', [
                'BID' => $id,
                'BKIND' => OAuthToken::KIND_ASSERTION,
                'BCLIENTID' => $clientId,
                'BUSERID' => null,
                'BSCOPES' => '[]',
                'BPARENTID' => null,
                'BEXPIRES' => $expires,
                'BREVOKED' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Deletes rows that expired before $before, at most PURGE_BATCH per call
     * so one call never holds the table for long.
     */
    public function purgeExpired(int $before): int
    {
        $statement = $this->getEntityManager()->getConnection()->prepare(
            'DELETE FROM BOAUTHTOKENS WHERE BEXPIRES < :before LIMIT '.self::PURGE_BATCH,
        );
        $statement->bindValue('before', $before, ParameterType::INTEGER);

        return (int) $statement->executeStatement();
    }
}
