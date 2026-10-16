<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VoiceSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VoiceSession>
 */
class VoiceSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceSession::class);
    }

    public function findOpenForUser(int $userId): ?VoiceSession
    {
        $session = $this->createQueryBuilder('s')
            ->andWhere('s.userId = :userId')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('userId', $userId)
            ->setParameter('statuses', VoiceSession::OPEN_STATUSES)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $session instanceof VoiceSession ? $session : null;
    }

    /**
     * @return list<VoiceSession>
     */
    public function findOpen(): array
    {
        /** @var list<VoiceSession> $sessions */
        $sessions = $this->createQueryBuilder('s')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', VoiceSession::OPEN_STATUSES)
            ->getQuery()
            ->getResult();

        return $sessions;
    }

    public function deleteByUser(int $userId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
