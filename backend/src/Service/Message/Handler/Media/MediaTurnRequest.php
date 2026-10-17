<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;

/**
 * One media turn once its model is known: the message and its request, the
 * prompt, the media type, the pictures it starts from, the model that
 * renders it and the account whose settings and jobs it runs under.
 */
final readonly class MediaTurnRequest
{
    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     */
    public function __construct(
        public Message $message,
        public array $thread,
        public array $classification,
        public array $options,
        public string $prompt,
        public string $mediaType,
        public MediaReferences $references,
        public MediaModel $model,
        public ?int $effectiveUserId,
    ) {
    }

    public function language(): string
    {
        return (string) ($this->classification['language'] ?? 'en');
    }

    /**
     * The usage limit the media type counts against.
     */
    public function mediaAction(): string
    {
        return match ($this->mediaType) {
            'image' => 'IMAGES',
            'video' => 'VIDEOS',
            'audio' => 'AUDIOS',
            default => 'IMAGES',
        };
    }

    /**
     * The media type in the user's status line.
     */
    public function mediaTypeLabel(): string
    {
        return match ($this->mediaType) {
            'video' => 'video',
            'audio' => 'audio',
            default => 'image',
        };
    }
}
