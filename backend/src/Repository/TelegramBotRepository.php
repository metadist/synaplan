<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramBot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramBot>
 */
class TelegramBotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramBot::class);
    }

    public function findOneByOwner(int $ownerId): ?TelegramBot
    {
        return $this->findOneBy(['ownerId' => $ownerId]);
    }

    public function findOneByBotKey(string $botKey): ?TelegramBot
    {
        return $this->findOneBy(['botKey' => $botKey]);
    }

    /**
     * Another owner's row that currently holds the webhook of this bot.
     */
    public function findActiveByBotIdForOtherOwner(int $botId, int $ownerId): ?TelegramBot
    {
        return $this->createQueryBuilder('b')
            ->where('b.botId = :botId')
            ->andWhere('b.ownerId != :ownerId')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('botId', $botId)
            ->setParameter('ownerId', $ownerId)
            ->setParameter('statuses', [TelegramBot::STATUS_PENDING, TelegramBot::STATUS_CONNECTED])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(TelegramBot $bot, bool $flush = true): void
    {
        $this->getEntityManager()->persist($bot);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TelegramBot $bot, bool $flush = true): void
    {
        $this->getEntityManager()->remove($bot);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
