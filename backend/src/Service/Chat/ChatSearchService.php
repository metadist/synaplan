<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;

/**
 * Free-text search across a user's chats.
 *
 * The chat list was previously only filterable by title, so a conversation could
 * not be found by what was said in it. This searches the message bodies as well
 * and returns an excerpt around the match, so the list can show WHY a chat hit.
 */
final readonly class ChatSearchService
{
    /**
     * Shortest term accepted. One character matches almost everything and turns
     * the LIKE scan into a full table read for no user benefit.
     */
    public const MIN_TERM_LENGTH = 2;

    /** Characters of context kept on each side of the match. */
    private const SNIPPET_RADIUS = 60;

    public function __construct(
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
    ) {
    }

    /**
     * Normalize a raw query parameter into a usable term.
     *
     * Returns null when the input is absent or too short, which callers treat as
     * "no search" rather than "no results".
     */
    public function normalizeTerm(?string $raw): ?string
    {
        $term = trim((string) $raw);

        return mb_strlen($term) >= self::MIN_TERM_LENGTH ? $term : null;
    }

    /**
     * @return array{chats: list<Chat>, total: int, snippets: array<int, string>}
     */
    public function search(int $userId, string $term, int $limit, int $offset): array
    {
        $chats = $this->chatRepository->searchByUser($userId, $term, $limit, $offset);
        $total = $this->chatRepository->countSearchByUser($userId, $term);

        $chatIds = array_map(static fn (Chat $chat): int => (int) $chat->getId(), $chats);
        $matches = $this->messageRepository->findMatchingTextForChats($chatIds, $term);

        $snippets = [];
        foreach ($matches as $chatId => $text) {
            $snippet = $this->buildSnippet($text, $term);
            if (null !== $snippet) {
                $snippets[$chatId] = $snippet;
            }
        }

        return ['chats' => $chats, 'total' => $total, 'snippets' => $snippets];
    }

    /**
     * Cut an excerpt around the first occurrence of the term, with ellipses where
     * text was removed. Returns null when the term is not in the text — that
     * happens when the chat matched on its title instead.
     */
    public function buildSnippet(string $text, string $term): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $position = mb_stripos($normalized, $term);

        if (false === $position) {
            return null;
        }

        $start = max(0, $position - self::SNIPPET_RADIUS);
        $length = mb_strlen($term) + (2 * self::SNIPPET_RADIUS);
        $excerpt = mb_substr($normalized, $start, $length);

        if ($start > 0) {
            $excerpt = '…'.$excerpt;
        }
        if ($start + $length < mb_strlen($normalized)) {
            $excerpt .= '…';
        }

        return $excerpt;
    }
}
