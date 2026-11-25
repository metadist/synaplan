<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Word-for-word search in one chat (03 §3, `conversation_search`, §0 #27),
 * and the messages around one of them.
 *
 * Bound to the chat's owner: a chat ID of someone else finds nothing. Terms
 * are matched literally; `%`, `_` and `\` in them are escaped.
 *
 * @extends ServiceEntityRepository<Message>
 */
final class ChatMessageSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Messages that contain every term, newest first, without the ones in
     * `$except` (the run's own question and reply).
     *
     * @param list<string> $terms
     * @param list<int>    $except
     *
     * @return list<Message>
     */
    public function search(int $userId, int $chatId, array $terms, int $limit, array $except = []): array
    {
        if ($chatId <= 0 || [] === $terms || $limit <= 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->join('m.chat', 'c')
            ->where('m.chatId = :chatId')
            ->andWhere('c.userId = :userId')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit);
        if ([] !== $except) {
            $qb->andWhere('m.id NOT IN (:except)')->setParameter('except', $except);
        }
        foreach ($terms as $index => $term) {
            $qb->andWhere('LOWER(m.text) LIKE :term'.$index)
                ->setParameter('term'.$index, '%'.addcslashes(mb_strtolower($term), '%_\\').'%');
        }

        /** @var list<Message> $messages */
        $messages = $qb->getQuery()->getResult();

        return $messages;
    }

    /**
     * The message and up to `$each` messages before and after it, oldest
     * first; empty when the message is not in this chat.
     *
     * @return list<Message>
     */
    public function around(int $userId, int $chatId, int $messageId, int $each): array
    {
        if ($chatId <= 0 || $messageId <= 0) {
            return [];
        }

        $centre = $this->createQueryBuilder('m')
            ->select('m.id')
            ->join('m.chat', 'c')
            ->where('m.id = :id')
            ->andWhere('m.chatId = :chatId')
            ->andWhere('c.userId = :userId')
            ->setParameter('id', $messageId)
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
        if (null === $centre) {
            return [];
        }

        $before = $this->side($chatId, $messageId, '<=', 'DESC', $each + 1);
        $after = $this->side($chatId, $messageId, '>', 'ASC', $each);

        return [...array_reverse($before), ...$after];
    }

    /** @return list<Message> */
    private function side(int $chatId, int $messageId, string $operator, string $order, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<Message> $messages */
        $messages = $this->createQueryBuilder('m')
            ->where('m.chatId = :chatId')
            ->andWhere('m.id '.$operator.' :id')
            ->setParameter('chatId', $chatId)
            ->setParameter('id', $messageId)
            ->orderBy('m.id', $order)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $messages;
    }
}
