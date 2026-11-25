<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Message;
use App\Repository\ChatMessageSearchRepository;

/**
 * `conversation_search` (03 §3, §0 #27): earlier messages of the chat stay
 * findable word for word, also after the run compacted its transcript.
 *
 * `query` finds messages that contain every word and returns a snippet of
 * each; `around` reads the messages next to one of them. Only this chat of
 * this person is searched.
 */
final readonly class ChatRunConversationSearch
{
    public const NAME = 'conversation_search';

    public const NEEDS_INPUT = 'conversation_search needs query (words to find) or around (a message id from an earlier result).';
    public const NOTHING = 'No message of this chat contains all of these words. Try fewer or other words.';
    public const NOT_IN_CHAT = 'There is no message with this id in this chat.';

    private const MAX_TERMS = 6;
    private const MAX_QUERY_CHARS = 200;
    private const HITS = 8;
    private const SNIPPET_CHARS = 300;
    private const NEIGHBOURS = 3;
    private const MESSAGE_CHARS = 1_500;

    public function __construct(
        private ChatMessageSearchRepository $messages,
    ) {
    }

    /** @return array<string, mixed> */
    public function declaration(): array
    {
        return ChatLoopTools::fn(self::NAME, 'Search every earlier message of this chat word for word. Returns snippets with message id, who wrote it and when. Pass around with a message id to read the messages next to it.', [
            'query' => ['type' => 'string', 'description' => 'Words that must all appear in the message.'],
            'around' => ['type' => 'integer', 'description' => 'A message id from an earlier result: read it with the messages before and after it.'],
        ], []);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<int>            $except the run's own question and reply, already in its transcript
     */
    public function run(ChatRunScope $scope, array $args, array $except = []): ChatToolResult
    {
        $userId = (int) $scope->user->getId();
        $chatId = (int) $scope->chat->getId();
        $around = $args['around'] ?? null;
        if (\is_string($around) && ctype_digit($around)) {
            $around = (int) $around;
        }
        if (\is_int($around) && $around > 0) {
            $list = $this->messages->around($userId, $chatId, $around, self::NEIGHBOURS);
            if ([] === $list) {
                return ChatToolResult::failed(self::NOT_IN_CHAT);
            }

            return ChatToolResult::text(implode("\n\n", array_map(static fn (Message $m): string => self::head($m)."\n".self::clip(trim($m->getText()), self::MESSAGE_CHARS), $list)));
        }

        $terms = self::terms($args['query'] ?? null);
        if ([] === $terms) {
            return ChatToolResult::failed(self::NEEDS_INPUT);
        }
        $hits = $this->messages->search($userId, $chatId, $terms, self::HITS, $except);
        if ([] === $hits) {
            return ChatToolResult::text(self::NOTHING);
        }
        $lines = [];
        foreach ($hits as $message) {
            $lines[] = self::head($message)."\n".self::snippet($message->getText(), $terms[0]);
        }

        return ChatToolResult::text(implode("\n\n", $lines)."\n\nNewest first. Pass around with a message id to read more of it.");
    }

    /** @return list<string> */
    private static function terms(mixed $query): array
    {
        if (!\is_string($query)) {
            return [];
        }
        $words = preg_split('/\s+/u', trim(mb_substr($query, 0, self::MAX_QUERY_CHARS))) ?: [];
        $out = [];
        foreach ($words as $word) {
            $word = trim($word, " \t\"'.,;:!?()[]{}");
            if (mb_strlen($word) >= 2 && !\in_array(mb_strtolower($word), array_map('mb_strtolower', $out), true)) {
                $out[] = $word;
            }
        }

        return \array_slice($out, 0, self::MAX_TERMS);
    }

    private static function head(Message $message): string
    {
        $who = 'IN' === $message->getDirection() ? 'the person' : 'assistant';
        $when = $message->getUnixTimestamp() > 0 ? ' · '.date('Y-m-d H:i', $message->getUnixTimestamp()) : '';

        return 'message '.$message->getId().' · '.$who.$when;
    }

    private static function snippet(string $text, string $term): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $at = mb_stripos($text, $term);
        $start = false === $at ? 0 : max(0, $at - intdiv(self::SNIPPET_CHARS, 3));
        $part = mb_substr($text, $start, self::SNIPPET_CHARS);

        return ($start > 0 ? '…' : '').$part.($start + mb_strlen($part) < mb_strlen($text) ? '…' : '');
    }

    private static function clip(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars).'…' : $text;
    }
}
