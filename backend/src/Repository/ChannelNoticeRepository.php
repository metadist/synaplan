<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChannelNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelNotice>
 */
class ChannelNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelNotice::class);
    }

    public function findOne(int $ownerId, string $channel, int $bindingId): ?ChannelNotice
    {
        $row = $this->findOneBy([
            'ownerId' => $ownerId,
            'channel' => $channel,
            'bindingId' => $bindingId,
        ]);

        return $row instanceof ChannelNotice ? $row : null;
    }

    /**
     * @return list<ChannelNotice>
     */
    public function forOwner(int $ownerId, string $channel): array
    {
        return $this->findBy(['ownerId' => $ownerId, 'channel' => $channel], ['bindingId' => 'ASC']);
    }

    public function save(ChannelNotice $notice): void
    {
        $this->getEntityManager()->persist($notice);
        $this->getEntityManager()->flush();
    }
}
