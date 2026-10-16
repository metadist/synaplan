<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;

/**
 * The stored reply of a streamed turn.
 *
 * `text` is what was stored: the answer, a file marker
 * (`__FILE_GENERATED__:<name>`, `__FILE_GENERATION_FAILED__`), or the
 * placeholder of a running media job.
 */
final readonly class StreamReply
{
    /**
     * @param list<array<string, mixed>>|null $ragSources    the knowledge-base sources, as stored
     * @param array<string, mixed>|null       $searchResults the web search behind the answer
     */
    public function __construct(
        public Message $outgoing,
        public string $text,
        public ?File $generatedFile = null,
        public ?array $ragSources = null,
        public ?array $searchResults = null,
    ) {
    }
}
