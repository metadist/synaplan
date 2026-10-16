<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * A validated request to stream one chat turn.
 *
 * `chatId` and `trackId` keep the values the client sent: both travel back in
 * the SSE payloads unchanged. An incognito turn has no chat and brings its
 * own history. `rateLimitError` is set when the person is over a limit; the
 * turn then only reports it, before anything is stored.
 */
final readonly class StreamTurnRequest
{
    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $incognitoHistory
     * @param array<string, mixed>|null                              $rateLimitError
     */
    public function __construct(
        public StreamCaller $caller,
        public ?string $chatId,
        public int|string $trackId,
        public StreamUserInput $input,
        public StreamTurnOptions $options,
        public bool $incognito = false,
        public array $incognitoHistory = [],
        public ?array $rateLimitError = null,
    ) {
    }
}
