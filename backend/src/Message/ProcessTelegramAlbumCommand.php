<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Answers a Telegram album once all of its parts had time to arrive.
 */
final readonly class ProcessTelegramAlbumCommand
{
    public function __construct(
        private int $botRowId,
        private string $mediaGroupId,
    ) {
    }

    public function getBotRowId(): int
    {
        return $this->botRowId;
    }

    public function getMediaGroupId(): string
    {
        return $this->mediaGroupId;
    }
}
