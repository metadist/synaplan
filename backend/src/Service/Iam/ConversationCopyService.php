<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\RAG\RagScopeResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Continues a shared conversation as a new chat owned by the member.
 * File binaries stay with the owner; the copy stores {@see RagScopeResolver::SHARED_FILE_REF}.
 */
final readonly class ConversationCopyService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
    ) {
    }

    public function copyForUser(Chat $source, User $user): Chat
    {
        $copy = new Chat();
        $copy->setUserId((int) $user->getId());
        $title = $source->getTitle();
        $copy->setTitle(null !== $title && '' !== $title ? $title : ('#'.(string) $source->getId()));
        $copy->setSource('web');
        $copy->setIsPublic(false);
        $this->em->persist($copy);
        $this->em->flush();

        /** @var list<Message> $messages */
        $messages = $this->messageRepository->findBy(
            ['chatId' => $source->getId()],
            ['unixTimestamp' => 'ASC', 'id' => 'ASC'],
        );

        foreach ($messages as $original) {
            $this->copyMessage($original, $copy, (int) $user->getId());
        }

        $this->em->flush();

        return $copy;
    }

    private function copyMessage(Message $original, Chat $copy, int $userId): void
    {
        $message = new Message();
        $message->setUserId($userId);
        $message->setChat($copy);
        $message->setTrackingId((int) (microtime(true) * 1000) + (int) $original->getId());
        $message->setProviderIndex($original->getProviderIndex());
        $message->setUnixTimestamp($original->getUnixTimestamp());
        $message->setDateTime($original->getDateTime());
        $message->setMessageType($original->getMessageType());
        $message->setFile($original->hasFile() ? 1 : 0);
        $message->setFilePath('');
        $message->setFileType($original->getFileType());
        $message->setTopic($original->getTopic());
        $message->setLanguage($original->getLanguage());
        $message->setText($original->getText());
        $message->setDirection($original->getDirection());
        $message->setStatus($original->getStatus());
        $message->setFileText($original->getFileText());

        $this->em->persist($message);
        $this->em->flush();

        $fileIds = $this->sourceFileIds($original);
        if ([] !== $fileIds) {
            $message->setFile(1);
            $message->setMeta(RagScopeResolver::SHARED_FILE_REF, implode(',', $fileIds));
            $this->em->flush();
        }
    }

    /**
     * @return list<int>
     */
    private function sourceFileIds(Message $original): array
    {
        $ids = [];
        foreach ($original->getFiles() as $file) {
            if (null !== $file->getId()) {
                $ids[] = (int) $file->getId();
            }
        }
        $messageId = $original->getId();
        if (null !== $messageId) {
            foreach ($this->fileRepository->findBy(['messageId' => $messageId]) as $file) {
                if (null !== $file->getId()) {
                    $ids[] = (int) $file->getId();
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
