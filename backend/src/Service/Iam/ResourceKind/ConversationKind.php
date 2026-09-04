<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Service\Iam\Permission;

/**
 * Conversation identity is BCHATS.BID. Shareable permissions in v1: read, use.
 */
final readonly class ConversationKind implements ShareableResourceKindInterface
{
    public const KEY = 'conversation';

    public function __construct(
        private ChatRepository $chatRepository,
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

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use];
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
