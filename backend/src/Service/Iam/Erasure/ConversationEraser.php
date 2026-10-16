<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Chat;
use App\Entity\ChatSummary;
use App\Entity\EmailVerificationAttempt;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\ChatSummaryRepository;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\MessageRepository;
use App\Service\Message\ConversationSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's chats, messages, rolling conversation summaries and email
 * verification attempts. The summaries' Redis copies are evicted after the
 * commit; their TTL is the backstop, and it does not depend on any row.
 */
#[AsTaggedItem(priority: 130)]
final readonly class ConversationEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chats,
        private MessageRepository $messages,
        private ChatSummaryRepository $chatSummaries,
        private ConversationSummaryService $summaries,
        private EmailVerificationAttemptRepository $emailVerificationAttempts,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $chats = $this->chats->findBy(['userId' => $erasure->userId]);
        $rows = [
            ...$chats,
            ...$this->messages->findBy(['userId' => $erasure->userId]),
            ...$this->emailVerificationAttempts->findBy(['email' => $erasure->email]),
        ];
        foreach ($rows as $row) {
            $this->em->remove($row);
        }

        $this->chatSummaries->deleteByUser($erasure->userId);
        $chatIds = array_values(array_filter(array_map(static fn (Chat $chat): ?int => $chat->getId(), $chats)));
        if ([] !== $chatIds) {
            $erasure->afterCommit(fn () => $this->summaries->evictCached($chatIds));
        }
    }

    public static function covers(): array
    {
        return [Chat::class, Message::class, ChatSummary::class, EmailVerificationAttempt::class];
    }
}
