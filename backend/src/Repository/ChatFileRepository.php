<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every file of one chat, uncapped and paged: files attached to its
 * messages, files made for its messages, and, when the chat is in a
 * project, the project's sources (its knowledge folder).
 *
 * Each query is bound to the person: a file counts only when it is theirs
 * and the chat is theirs, so a file ID from somewhere else never resolves.
 *
 * @extends ServiceEntityRepository<File>
 */
final class ChatFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /**
     * Newest first.
     *
     * @return list<File>
     */
    public function page(int $userId, int $chatId, ?string $groupKey, int $offset, int $limit): array
    {
        if ($chatId <= 0 || $limit <= 0) {
            return [];
        }

        /** @var list<File> $files */
        $files = $this->scoped($userId, $chatId, $groupKey)
            ->orderBy('f.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $files;
    }

    public function total(int $userId, int $chatId, ?string $groupKey): int
    {
        if ($chatId <= 0) {
            return 0;
        }

        return (int) $this->scoped($userId, $chatId, $groupKey)
            ->select('COUNT(DISTINCT f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The file with this ID when it belongs to the chat (or its project), else null. */
    public function findInChat(int $userId, int $chatId, ?string $groupKey, int $fileId): ?File
    {
        if ($chatId <= 0 || $fileId <= 0) {
            return null;
        }

        $file = $this->scoped($userId, $chatId, $groupKey)
            ->andWhere('f.id = :fileId')
            ->setParameter('fileId', $fileId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $file instanceof File ? $file : null;
    }

    private function scoped(int $userId, int $chatId, ?string $groupKey): QueryBuilder
    {
        $em = $this->getEntityManager();
        $messageIds = $em->createQueryBuilder()
            ->select('chatMessage.id')
            ->from(Message::class, 'chatMessage')
            ->where('chatMessage.chatId = :chatId')
            ->andWhere('chatMessage.userId = :userId')
            ->getDQL();
        $attachedIds = $em->createQueryBuilder()
            ->select('attached.id')
            ->from(Message::class, 'msg')
            ->innerJoin('msg.files', 'attached')
            ->where('msg.chatId = :chatId')
            ->andWhere('msg.userId = :userId')
            ->getDQL();

        $inChat = 'f.messageId IN ('.$messageIds.') OR f.id IN ('.$attachedIds.')';
        $qb = $this->createQueryBuilder('f')
            ->distinct()
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->setParameter('chatId', $chatId);

        if (null !== $groupKey && '' !== $groupKey) {
            // A project source the cloud reported deleted, or an incognito
            // upload, is hidden from the Files list; a run does not see it either.
            $source = 'f.groupKey = :groupKey AND f.sourceDeletedAt IS NULL AND f.ephemeral = false';

            return $qb->andWhere('('.$inChat.' OR ('.$source.'))')->setParameter('groupKey', $groupKey);
        }

        return $qb->andWhere('('.$inChat.')');
    }
}
