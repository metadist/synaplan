<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * What one Telegram message carries: its text or caption, at most one file,
 * and shared content (location, contact, poll, dice) as text for the AI.
 */
final readonly class TelegramIncoming
{
    /** Content we receive but cannot map to anything the web chat does. */
    private const UNSUPPORTED_KEYS = ['game', 'story', 'invoice', 'paid_media', 'giveaway', 'giveaway_winners', 'checklist'];

    /**
     * @param array<string, mixed>|null $payload raw shared content, kept on the stored message
     */
    public function __construct(
        public string $text,
        public ?TelegramMediaRef $media,
        public ?string $shared,
        public ?array $payload,
        public ?string $mediaGroupId,
        public ?string $messageId,
        public ?int $replyToMessageId,
        public bool $unsupported,
        public bool $liveLocation,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function fromMessage(array $message): self
    {
        $text = self::string($message['text'] ?? null) ?? self::string($message['caption'] ?? null) ?? '';
        $replyTo = $message['reply_to_message']['message_id'] ?? null;
        $group = $message['media_group_id'] ?? null;
        [$shared, $payload, $live] = TelegramStructuredInput::describe($message);

        $unsupported = false;
        foreach (self::UNSUPPORTED_KEYS as $key) {
            if (isset($message[$key])) {
                $unsupported = true;
            }
        }

        return new self(
            trim($text),
            self::media($message),
            $shared,
            $payload,
            is_scalar($group) ? (string) $group : null,
            self::id($message['message_id'] ?? null),
            is_int($replyTo) ? $replyTo : null,
            $unsupported,
            $live,
        );
    }

    public function isEmpty(): bool
    {
        return '' === $this->text && null === $this->media && null === $this->shared;
    }

    public function isCommand(): bool
    {
        return null === $this->media && null === $this->shared && str_starts_with($this->text, '/');
    }

    /**
     * The text stored as the person's message and read by the AI.
     */
    public function prompt(): string
    {
        $parts = [];
        if (null !== $this->shared) {
            $parts[] = $this->shared;
        }
        if (null !== $this->media?->emoji) {
            $parts[] = '[Sticker '.$this->media->emoji.']';
        }
        if ('' !== $this->text) {
            $parts[] = $this->text;
        } elseif (null !== $this->media) {
            $default = self::defaultPrompt($this->media->kind);
            if ('' !== $default) {
                $parts[] = $default;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<TelegramMediaRef> $media
     */
    public static function albumPrompt(array $media): string
    {
        foreach ($media as $ref) {
            if (TelegramMediaRef::PHOTO !== $ref->kind) {
                return 'Summarize these files.';
            }
        }

        return 1 === count($media) ? self::defaultPrompt(TelegramMediaRef::PHOTO) : 'Describe what you see in these images.';
    }

    private static function defaultPrompt(string $kind): string
    {
        return match ($kind) {
            TelegramMediaRef::PHOTO => 'Describe what you see in this image.',
            TelegramMediaRef::STICKER => 'Describe this sticker. What does it show and what emotion or message does it convey?',
            TelegramMediaRef::VIDEO, TelegramMediaRef::ANIMATION => 'Describe this video and summarize what is said in it.',
            TelegramMediaRef::VIDEO_NOTE => 'Reply to what is said in this video message.',
            TelegramMediaRef::DOCUMENT => 'Summarize this file.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function media(array $message): ?TelegramMediaRef
    {
        $photo = $message['photo'] ?? null;
        if (is_array($photo) && [] !== $photo) {
            return self::largestPhoto($photo);
        }
        $sticker = $message['sticker'] ?? null;
        if (is_array($sticker)) {
            return self::sticker($sticker);
        }
        foreach ([TelegramMediaRef::VOICE, TelegramMediaRef::VIDEO_NOTE, TelegramMediaRef::AUDIO, TelegramMediaRef::ANIMATION, TelegramMediaRef::VIDEO, TelegramMediaRef::DOCUMENT] as $kind) {
            $item = $message[$kind] ?? null;
            if (is_array($item)) {
                return self::ref($kind, $item);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $sizes
     */
    private static function largestPhoto(array $sizes): ?TelegramMediaRef
    {
        $best = null;
        foreach ($sizes as $size) {
            if (!is_array($size)) {
                continue;
            }
            $bytes = is_int($size['file_size'] ?? null) ? $size['file_size'] : null;
            if (null !== $bytes && $bytes > TelegramBotApi::MAX_DOWNLOAD_BYTES && null !== $best) {
                continue;
            }
            $best = $size;
        }

        return is_array($best) ? self::ref(TelegramMediaRef::PHOTO, $best) : null;
    }

    /**
     * Animated (Lottie) and video stickers cannot be analysed as an image,
     * so their still thumbnail stands in for them.
     *
     * @param array<string, mixed> $sticker
     */
    private static function sticker(array $sticker): ?TelegramMediaRef
    {
        $emoji = self::string($sticker['emoji'] ?? null);
        $moving = !empty($sticker['is_animated']) || !empty($sticker['is_video']);
        $source = $moving ? ($sticker['thumbnail'] ?? $sticker['thumb'] ?? null) : $sticker;
        if (!is_array($source)) {
            return null;
        }
        $ref = self::ref(TelegramMediaRef::STICKER, $source);

        return null === $ref ? null : new TelegramMediaRef($ref->kind, $ref->fileId, $ref->fileSize, null, null, $emoji);
    }

    /**
     * @param array<mixed> $item
     */
    private static function ref(string $kind, array $item): ?TelegramMediaRef
    {
        $fileId = self::string($item['file_id'] ?? null);
        if (null === $fileId) {
            return null;
        }
        $size = $item['file_size'] ?? null;

        return new TelegramMediaRef(
            $kind,
            $fileId,
            is_int($size) ? $size : null,
            self::string($item['file_name'] ?? null),
            self::string($item['mime_type'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private static function id(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && ctype_digit($value) ? $value : null;
    }
}
