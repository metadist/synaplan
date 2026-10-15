<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

/**
 * The assembled system prompt of a chat turn.
 *
 * `prompt` is null when the model rejects the system role; the text then
 * travels as `userTurnFallback` in front of the first user turn. Parts the
 * prompt took over (the quoted excerpt, the web results) are gone from
 * `options` and `searchResults`, so the user turn does not repeat them.
 */
final readonly class ChatSystemPrompt
{
    /**
     * @param list<array{slug: string, title: string, url: string}> $docs          platform documentation the prompt cites
     * @param array<string, mixed>                                  $options       the turn options without the parts the prompt took over
     * @param array<string, mixed>|null                             $searchResults web results still meant for the user turn (buffered turns)
     */
    public function __construct(
        public ?string $prompt,
        public ?string $userTurnFallback,
        public array $docs,
        public array $options,
        public ?array $searchResults = null,
    ) {
    }
}
