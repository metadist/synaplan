<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConversationFileLinkRepository;
use App\Repository\FileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Takes a file out of one chat without deleting it.
 *
 * The file row, its bytes, and its place in the user's files stay. Only the
 * links that make this conversation still "have" the file are cleared, so a
 * later question no longer uses it.
 */
final readonly class ConversationFileDetacher
{
    public function __construct(
        private FileRepository $files,
        private ConversationFileLinkRepository $links,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function detach(int $ownerId, int $chatId, int $fileId): bool
    {
        $file = $this->files->find($fileId);
        if (!$file instanceof File || $file->getUserId() !== $ownerId) {
            return false;
        }

        $messages = $this->links->messagesInChatTouchingFile($ownerId, $chatId, $file);
        if ([] === $messages) {
            return false;
        }

        foreach ($messages as $message) {
            $this->unlink($message, $file);
        }

        $this->entityManager->flush();

        return true;
    }

    private function unlink(Message $message, File $file): void
    {
        $message->removeFile($file);

        if ($this->samePath($message->getFilePath(), $file->getFilePath())) {
            $message->setFilePath('');
            $message->setFile(0);
        }

        if (null !== $file->getMessageId() && $file->getMessageId() === $message->getId()) {
            $file->setMessageId(null);
        }
    }

    private function samePath(string $left, string $right): bool
    {
        $left = ltrim(trim($left), '/');
        $right = ltrim(trim($right), '/');

        return '' !== $left && $left === $right;
    }
}
