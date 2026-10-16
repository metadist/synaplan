<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * An excerpt the person picked from an earlier message ("Mention in chat").
 *
 * `messageId` is the claimed source; the turn keeps it only when that
 * message belongs to the same chat and person.
 */
final readonly class StreamQuote
{
    public function __construct(
        public string $text,
        public ?int $messageId = null,
    ) {
    }
}
