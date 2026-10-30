<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Messages in one chat that still point at a file, through the attachment
 * join, the file's message id, or the legacy path column.
 */
final readonly class ConversationFileLinkRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Message>
     */
    public function messagesInChatTouchingFile(int $userId, int $chatId, File $file): array
    {
        $paths = $this->pathVariants($file->getFilePath());

        /** @var list<Message> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT message')
            ->from(Message::class, 'message')
            ->leftJoin('message.files', 'attached')
            ->where('message.userId = :userId')
            ->andWhere('message.chatId = :chatId')
            ->andWhere('attached = :file OR message.id = :messageId OR message.filePath IN (:paths)')
            ->setParameter('userId', $userId)
            ->setParameter('chatId', $chatId)
            ->setParameter('file', $file)
            ->setParameter('messageId', $file->getMessageId() ?? 0)
            ->setParameter('paths', $paths)
            ->getQuery()
            ->getResult();

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function pathVariants(string $path): array
    {
        $trimmed = trim($path);
        if ('' === $trimmed) {
            return ['__conversation_file_detach_none__'];
        }

        $bare = ltrim($trimmed, '/');

        return array_values(array_unique([$trimmed, $bare, '/'.$bare]));
    }
}
