<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Service\Iam\ConversationFeedbackCleanup;
use App\Service\Iam\Permission;
use Psr\Log\LoggerInterface;

/**
 * Conversation identity is BCHATS.BID. Shareable permissions in v1: read, use.
 */
final readonly class ConversationKind implements ShareableResourceKindInterface
{
    public const KEY = 'conversation';

    public function __construct(
        private ChatRepository $chatRepository,
        private ConversationFeedbackCleanup $feedbackCleanup,
        private LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        $chat = $this->findChat($resourceId);
        if (null === $chat) {
            return null;
        }

        return $chat->getUserId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $chat = $this->findChat($resourceId);
        if (null === $chat) {
            return new ResourceCard($resourceId, $resourceId, 'chat');
        }

        $title = $chat->getTitle();

        return new ResourceCard(
            (string) $chat->getId(),
            (null !== $title && '' !== $title) ? $title : ('#'.(string) $chat->getId()),
            'chat',
            ['ownerId' => $chat->getUserId()],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->chatRepository->findByUser($userId) as $chat) {
            if (!$chat instanceof Chat) {
                continue;
            }
            $id = (string) $chat->getId();
            $title = $chat->getTitle();
            yield new ResourceCard(
                $id,
                (null !== $title && '' !== $title) ? $title : ('#'.$id),
                'chat',
                ['ownerId' => $chat->getUserId()],
            );
        }
    }

    public function onShareChanged(string $resourceId, ?array $revokedSubject = null): void
    {
        if (null === $revokedSubject) {
            return;
        }
        $chat = $this->findChat($resourceId);
        if (null === $chat) {
            return;
        }
        try {
            $this->feedbackCleanup->withdrawDerivedFromChat($chat);
        } catch (\Throwable $e) {
            // The share row is already gone: a cleanup failure must not fail
            // the revoke or leave the caller retrying a share that no longer
            // exists. Log loudly so the orphaned entries are found.
            $this->logger->error('Derived-feedback cleanup failed after share revoke', [
                'chat_id' => $chat->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use];
    }

    public function assertShareable(string $resourceId): void
    {
    }

    private function findChat(string $resourceId): ?Chat
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }

        $chat = $this->chatRepository->find((int) $resourceId);

        return $chat instanceof Chat ? $chat : null;
    }
}
