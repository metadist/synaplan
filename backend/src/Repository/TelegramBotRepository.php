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

    public function save(TelegramBot $bot, bool $flush = true): void
    {
        $this->getEntityManager()->persist($bot);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
