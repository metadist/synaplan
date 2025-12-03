<?php

namespace App\Repository;

use App\Entity\MessageMeta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageMeta>
 */
class MessageMetaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageMeta::class);
    }

    /**
     * Findet alle Meta-Daten für eine Message
     */
    public function findByMessage(int $messageId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet Meta-Daten nach Key
     */
    public function findByMessageAndKey(int $messageId, string $key): ?MessageMeta
    {
        return $this->createQueryBuilder('m')
            ->where('m.messageId = :messageId')
            ->andWhere('m.metaKey = :key')
            ->setParameter('messageId', $messageId)
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Speichert MessageMeta
     */
    public function save(MessageMeta $messageMeta, bool $flush = true): void
    {
        $this->getEntityManager()->persist($messageMeta);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht MessageMeta
     */
    public function remove(MessageMeta $messageMeta, bool $flush = true): void
    {
        $this->getEntityManager()->remove($messageMeta);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

