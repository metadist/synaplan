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

    /** Kinds whose spoken words are the message. */
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

    /**
     * @return array{kind: string, fileId: string, fileSize: int|null, fileName: string|null, mimeType: string|null, emoji: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'fileId' => $this->fileId,
            'fileSize' => $this->fileSize,
            'fileName' => $this->fileName,
            'mimeType' => $this->mimeType,
            'emoji' => $this->emoji,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $kind = $data['kind'] ?? null;
        $fileId = $data['fileId'] ?? null;
        if (!is_string($kind) || !is_string($fileId) || '' === $fileId) {
            return null;
        }
        $size = $data['fileSize'] ?? null;

        return new self(
            $kind,
            $fileId,
            is_int($size) ? $size : null,
            is_string($data['fileName'] ?? null) ? $data['fileName'] : null,
            is_string($data['mimeType'] ?? null) ? $data['mimeType'] : null,
            is_string($data['emoji'] ?? null) ? $data['emoji'] : null,
        );
    }
}
