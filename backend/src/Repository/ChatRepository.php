<?php

namespace App\Repository;

use App\Entity\Chat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A single page of a user's chats, most-recently-updated first.
     *
     * Used by the mobile history drawer's infinite scroll. `countByUser()`
     * provides the total so the client can decide whether more pages exist.
     *
     * @return list<Chat>
     */
    public function findByUserPaginated(int $userId, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.updatedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Most-recently-updated chats for a user, each paired with its message count,
     * resolved in a single SQL-limited query.
     *
     * Avoids hydrating full message collections just to count them (the N+1 trap
     * of `getMessages()->count()` when the relation is not EXTRA_LAZY).
     *
     * @return list<array{chat: Chat, messageCount: int}>
     */
    public function findByUserWithMessageCount(int $userId, int $limit): array
    {
        /** @var array<int, array{0: Chat, messageCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c', 'COUNT(m.id) AS messageCount')
            ->leftJoin('c.messages', 'm')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('c.id')
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'chat' => $row[0],
                'messageCount' => (int) $row['messageCount'],
            ],
            $rows,
        );
    }

    public function findByShareToken(string $token): ?Chat
    {
        return $this->findOneBy(['shareToken' => $token]);
    }

    public function findPublicByShareToken(string $token): ?Chat
    {
        return $this->findOneBy([
            'shareToken' => $token,
            'isPublic' => true,
        ]);
    }

    /**
     * Remove a chat entity.
     */
    public function remove(Chat $chat, bool $flush = false): void
    {
        $this->getEntityManager()->remove($chat);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
