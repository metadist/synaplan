<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * The usage a reply recorded: the chat call's payload for the usage badge
 * (null when there was nothing to show), and the auxiliary entries
 * (sorting, planning, transcription, media).
 */
final readonly class StreamUsage
{
    /**
     * @param array<string, mixed>|null  $chat
     * @param list<array<string, mixed>> $extra
     */
    public function __construct(
        public ?array $chat,
        public array $extra,
    ) {
    }
}
