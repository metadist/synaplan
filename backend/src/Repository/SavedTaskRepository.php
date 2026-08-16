<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedTask>
 */
class SavedTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedTask::class);
    }

    /**
     * @return list<SavedTask>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('t.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndOwner(int $id, int $ownerId): ?SavedTask
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    public function findByPromptAndOwner(int $promptId, int $ownerId): ?SavedTask
    {
        return $this->findOneBy(['promptId' => $promptId, 'ownerId' => $ownerId]);
    }

    public function findEnabledChatTaskForPrompt(int $promptId, int $ownerId): ?SavedTask
    {
        return $this->createQueryBuilder('t')
            ->where('t.promptId = :promptId')
            ->andWhere('t.ownerId = :ownerId')
            ->andWhere('t.enabled = true')
            ->andWhere('t.triggerType = :type')
            ->andWhere('t.graph IS NOT NULL')
            ->setParameter('promptId', $promptId)
            ->setParameter('ownerId', $ownerId)
            ->setParameter('type', SavedTask::TRIGGER_CHAT)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<SavedTask>
     */
    public function findDueScheduled(int $limit, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.enabled = true')
            ->andWhere('t.triggerType = :type')
            ->andWhere('t.nextRunAt IS NOT NULL')
            ->andWhere('t.nextRunAt <= :now')
            ->setParameter('type', SavedTask::TRIGGER_SCHEDULE)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('t.nextRunAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compare-and-set claim: only succeeds if next_run_at is still the expected value.
     */
    public function claim(SavedTask $task, \DateTimeImmutable $expectedNext, \DateTimeImmutable $tentativeNext): bool
    {
        $id = $task->getId();
        if (null === $id) {
            return false;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BSAVEDTASKS SET BNEXTRUNAT = :next, BUPDATED = :updated WHERE BID = :id AND BNEXTRUNAT = :expected AND BENABLED = 1',
            [
                'next' => $tentativeNext->format('Y-m-d H:i:s'),
                'updated' => time(),
                'id' => $id,
                'expected' => $expectedNext->format('Y-m-d H:i:s'),
            ]
        );

        return $affected > 0;
    }

    /**
     * @return list<SavedTask>
     */
    public function findEnabledInboundEmailTasks(int $ownerId, int $accountId): array
    {
        $tasks = $this->createQueryBuilder('t')
            ->where('t.ownerId = :ownerId')
            ->andWhere('t.enabled = true')
            ->andWhere('t.triggerType = :type')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('type', SavedTask::TRIGGER_INBOUND_EMAIL)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $tasks,
            static fn (SavedTask $task): bool => (int) ($task->getTriggerConfig()['accountId'] ?? 0) === $accountId
        ));
    }

    public function save(SavedTask $task, bool $flush = true): void
    {
        $this->getEntityManager()->persist($task);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SavedTask $task, bool $flush = true): void
    {
        $this->getEntityManager()->remove($task);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
