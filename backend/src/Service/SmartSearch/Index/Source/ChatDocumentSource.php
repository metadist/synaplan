<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ChatDisplayTitle;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\SharedResourceIds;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Chats: the title plus the first questions, so an untitled chat is still
 * found by what the person asked. Chats shared with the user are found in
 * the owner's rows and shown with the owner's name.
 */
final readonly class ChatDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'chat';
    private const INBOUND_TEXTS = 3;
    private const FALLBACK_TITLE_LENGTH = 80;
    /** Bounds the `IN (...)` list a search adds for shared chats. */
    private const MAX_SHARED = 500;

    public function __construct(
        private ChatRepository $chats,
        private MessageRepository $messages,
        private SharedResourceIds $sharedIds,
        private AccessGate $accessGate,
        private UserRepository $users,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return true;
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if ($entity instanceof Chat) {
            if ([] !== $changeSet && !array_key_exists('title', $changeSet)) {
                return null;
            }
            $id = $entity->getId();

            return null === $id ? null : ['userId' => $entity->getUserId(), 'refId' => (string) $id];
        }

        if ($entity instanceof Message && [] === $changeSet && 'IN' === $entity->getDirection()) {
            // The row belongs to the chat owner; in a shared chat the writer
            // (Message::getUserId()) can be someone else.
            // A message stored by chat id alone has no relation loaded; fall
            // back to its writer (app:search:reindex repairs a mismatch).
            $chat = $entity->getChat();
            $chatId = $chat?->getId() ?? $entity->getChatId();
            if (null === $chatId || $chatId <= 0) {
                return null;
            }

            return ['userId' => $chat?->getUserId() ?? $entity->getUserId(), 'refId' => (string) $chatId];
        }

        return null;
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $chat = $this->chats->find((int) $refId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $userId) {
            return null;
        }

        return $this->document($chat);
    }

    public function allForUser(int $userId): iterable
    {
        $chats = $this->chats->findBy(['userId' => $userId]);
        $chatIds = array_values(array_filter(array_map(static fn (Chat $chat): ?int => $chat->getId(), $chats)));
        $texts = $this->messages->findFirstInboundTextsForChats($chatIds, self::INBOUND_TEXTS);
        foreach ($chats as $chat) {
            $document = $this->document($chat, $texts[(int) $chat->getId()] ?? []);
            if (null !== $document) {
                yield $document;
            }
        }
    }

    public function sharedRefIds(int $userId): array
    {
        return array_map('strval', array_slice($this->sharedIds->forUser($userId, ConversationKind::KEY, Permission::Read), 0, self::MAX_SHARED));
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->chats->findBy(['userId' => $userId, 'id' => $ids]) as $chat) {
            $resolved[(string) $chat->getId()] = $this->item($chat);
        }

        $foreign = array_values(array_diff($ids, array_map('intval', array_keys($resolved))));
        $user = [] === $foreign ? null : $this->users->find($userId);
        if (!$user instanceof User) {
            return $resolved;
        }
        $shared = array_values(array_filter(
            $this->chats->findBy(['id' => $foreign]),
            fn (Chat $chat): bool => $this->accessGate->decide($user, ConversationKind::KEY, (string) $chat->getId(), Permission::Read),
        ));
        $owners = $this->ownerNames(array_map(static fn (Chat $chat): int => $chat->getUserId(), $shared));
        foreach ($shared as $chat) {
            $resolved[(string) $chat->getId()] = $this->item($chat, $owners[$chat->getUserId()] ?? '');
        }

        return $resolved;
    }

    private function item(Chat $chat, ?string $sharedBy = null): ResolvedItem
    {
        $id = (string) $chat->getId();
        $title = trim((string) $chat->getTitle());

        return new ResolvedItem(
            title: ChatDisplayTitle::isPlaceholder($title) ? '' : $title,
            route: '/?chat='.$id,
            sharedBy: $sharedBy,
        );
    }

    /**
     * @param list<int> $ownerIds
     *
     * @return array<int, string>
     */
    private function ownerNames(array $ownerIds): array
    {
        $names = [];
        foreach ([] === $ownerIds ? [] : $this->users->findBy(['id' => array_values(array_unique($ownerIds))]) as $owner) {
            $names[(int) $owner->getId()] = $owner->getDisplayName();
        }

        return $names;
    }

    /**
     * @param list<string>|null $inboundTexts preloaded first questions; null reads them
     */
    private function document(Chat $chat, ?array $inboundTexts = null): ?SearchDocument
    {
        $id = $chat->getId();
        if (null === $id) {
            return null;
        }

        $questions = array_values(array_filter(
            array_map(ChatDisplayTitle::humanText(...), $inboundTexts ?? $this->messages->findFirstInboundTexts($id, self::INBOUND_TEXTS)),
            static fn (string $text): bool => '' !== $text,
        ));
        $title = trim((string) $chat->getTitle());
        if ('' === $title || ChatDisplayTitle::isPlaceholder($title)) {
            if ([] === $questions) {
                return null;
            }
            $title = mb_substr($questions[0], 0, self::FALLBACK_TITLE_LENGTH);
        }

        return new SearchDocument(
            userId: $chat->getUserId(),
            kind: self::KIND,
            refId: (string) $id,
            title: $title,
            body: implode("\n", $questions),
            updated: $chat->getUpdatedAt()->getTimestamp(),
        );
    }
}
