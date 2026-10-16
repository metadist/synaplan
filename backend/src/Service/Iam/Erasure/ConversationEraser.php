<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Chat;
use App\Entity\EmailVerificationAttempt;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 130)]
final readonly class ConversationEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chats,
        private MessageRepository $messages,
        private EmailVerificationAttemptRepository $emailVerificationAttempts,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $rows = [
            ...$this->chats->findBy(['userId' => $erasure->userId]),
            ...$this->messages->findBy(['userId' => $erasure->userId]),
            ...$this->emailVerificationAttempts->findBy(['email' => $erasure->email]),
        ];
        foreach ($rows as $row) {
            $this->em->remove($row);
        }
    }

    public static function covers(): array
    {
        return [Chat::class, Message::class, EmailVerificationAttempt::class];
    }
}
