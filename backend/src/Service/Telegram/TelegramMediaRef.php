<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * One file a person sent to the bot, before it is downloaded.
 */
final readonly class TelegramMediaRef
{
    public const PHOTO = 'photo';
    public const DOCUMENT = 'document';
    public const VOICE = 'voice';
    public const AUDIO = 'audio';
    public const VIDEO = 'video';
    public const VIDEO_NOTE = 'video_note';
    public const ANIMATION = 'animation';
    public const STICKER = 'sticker';

    /** Kinds whose transcript becomes the person's message. */
    private const SPOKEN = [self::VOICE, self::AUDIO];

    public function __construct(
        public string $kind,
        public string $fileId,
        public ?int $fileSize,
        public ?string $fileName,
        public ?string $mimeType,
        public ?string $emoji = null,
    ) {
    }

    public function isSpoken(): bool
    {
        return in_array($this->kind, self::SPOKEN, true);
    }
}
