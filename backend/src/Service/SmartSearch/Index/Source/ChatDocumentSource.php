<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\Iam\ResourceKind\ChatDisplayTitle;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Chats: the title plus the first questions, so an untitled chat is still
 * found by what the person asked.
 */
final readonly class ChatDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'chat';
    private const INBOUND_TEXTS = 3;
    private const FALLBACK_TITLE_LENGTH = 80;

    public function __construct(
        private ChatRepository $chats,
        private MessageRepository $messages,
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
            $chatId = $entity->getChatId();

            return null === $chatId || $chatId <= 0 ? null : ['userId' => $entity->getUserId(), 'refId' => (string) $chatId];
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
        foreach ($this->chats->findBy(['userId' => $userId]) as $chat) {
            $document = $this->document($chat);
            if (null !== $document) {
                yield $document;
            }
        }
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->chats->findBy(['userId' => $userId, 'id' => $ids]) as $chat) {
            $id = (string) $chat->getId();
            $title = trim((string) $chat->getTitle());
            $resolved[$id] = new ResolvedItem(
                title: ChatDisplayTitle::isPlaceholder($title) ? '' : $title,
                route: '/?chat='.$id,
            );
        }

        return $resolved;
    }

    private function document(Chat $chat): ?SearchDocument
    {
        $id = $chat->getId();
        if (null === $id) {
            return null;
        }

        $questions = array_values(array_filter(
            array_map(ChatDisplayTitle::humanText(...), $this->messages->findFirstInboundTexts($id, self::INBOUND_TEXTS)),
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
