<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthToken>
 */
class OAuthTokenRepository extends ServiceEntityRepository
{
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
     * Revokes every token a client holds for a person, or for nobody in
     * particular when $userId is null. Returns how many were live.
     */
    public function revokeAll(string $clientId, ?int $userId): int
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

        return (int) $qb->getQuery()->execute();
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

    public function purgeExpired(int $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM BOAUTHTOKENS WHERE BEXPIRES < ? LIMIT 1000',
            [$before],
        );
    }
}
