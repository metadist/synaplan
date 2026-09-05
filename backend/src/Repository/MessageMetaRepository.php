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
     * Findet alle Meta-Daten für eine Message.
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
     * True when a message this user owns lists this file in shared_file_ref.
     */
    public function userHasSharedFileRef(int $userId, int $fileId): bool
    {
        $needle = (string) $fileId;
        $qb = $this->createQueryBuilder('meta');
        $count = $qb->select('COUNT(meta.id)')
            ->innerJoin('meta.message', 'm')
            ->where('m.userId = :userId')
            ->andWhere('meta.metaKey = :key')
            ->andWhere($qb->expr()->orX(
                'meta.metaValue = :exact',
                'meta.metaValue LIKE :prefix',
                'meta.metaValue LIKE :infix',
                'meta.metaValue LIKE :suffix',
            ))
            ->setParameter('userId', $userId)
            ->setParameter('key', 'shared_file_ref')
            ->setParameter('exact', $needle)
            ->setParameter('prefix', $needle.',%')
            ->setParameter('infix', '%,'.$needle.',%')
            ->setParameter('suffix', '%,'.$needle)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Findet Meta-Daten nach Key.
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
     * Speichert MessageMeta.
     */
    public function save(MessageMeta $messageMeta, bool $flush = true): void
    {
        $this->getEntityManager()->persist($messageMeta);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht MessageMeta.
     */
    public function remove(MessageMeta $messageMeta, bool $flush = true): void
    {
        $this->getEntityManager()->remove($messageMeta);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
