<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The tokens OpenAI reports for one GPT Image request, so the image is billed
 * at the published token rates instead of an estimated per-image price.
 *
 * The Images API returns them as `usage`. The Responses API reports the image
 * tool as `tool_usage.image_gen` and the mainline model that called the tool as
 * `usage`; that model is billed at its own chat rates (the wrapper fields).
 * Cached input counts are part of the input counts, as in OpenAI's usage.
 */
final readonly class ImageTokenUsage
{
    public function __construct(
        public int $textIn,
        public int $imageIn,
        public int $cachedTextIn,
        public int $cachedImageIn,
        public int $out,
        public ?string $wrapperModel = null,
        public int $wrapperIn = 0,
        public int $wrapperCachedIn = 0,
        public int $wrapperOut = 0,
    ) {
    }

    /**
     * `usage` of an Images API answer (generations or edits). Null when the
     * answer has no split of its input tokens: text and image input have
     * different rates, so an unsplit count cannot be priced.
     */
    public static function fromImagesApi(mixed $usage): ?self
    {
        if (!\is_array($usage) || !\is_array($usage['input_tokens_details'] ?? null) || !isset($usage['output_tokens'])) {
            return null;
        }

        return new self(
            textIn: self::count($usage['input_tokens_details']['text_tokens'] ?? 0),
            imageIn: self::count($usage['input_tokens_details']['image_tokens'] ?? 0),
            cachedTextIn: 0,
            cachedImageIn: 0,
            out: self::count($usage['output_tokens']),
        );
    }

    /**
     * A Responses API answer that called the image tool. Null when the answer
     * has no `tool_usage.image_gen` split.
     *
     * @param array<mixed> $response
     */
    public static function fromResponses(array $response, string $wrapperModel): ?self
    {
        $image = self::fromImagesApi($response['tool_usage']['image_gen'] ?? null);
        if (null === $image) {
            return null;
        }

        $cached = $response['tool_usage']['image_gen']['cached_tokens_details'] ?? [];
        $cached = \is_array($cached) ? $cached : [];
        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new self(
            textIn: $image->textIn,
            imageIn: $image->imageIn,
            cachedTextIn: min($image->textIn, self::count($cached['cached_text_tokens'] ?? 0)),
            cachedImageIn: min($image->imageIn, self::count($cached['cached_image_tokens'] ?? 0)),
            out: $image->out,
            wrapperModel: $wrapperModel,
            wrapperIn: self::count($usage['input_tokens'] ?? 0),
            wrapperCachedIn: self::count($usage['input_tokens_details']['cached_tokens'] ?? 0),
            wrapperOut: self::count($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * The shape stored in `media_usage.tokens`, read back by fromArray().
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'text_in' => $this->textIn,
            'image_in' => $this->imageIn,
            'cached_text_in' => $this->cachedTextIn,
            'cached_image_in' => $this->cachedImageIn,
            'out' => $this->out,
            'wrapper_model' => $this->wrapperModel,
            'wrapper_in' => $this->wrapperIn,
            'wrapper_cached_in' => $this->wrapperCachedIn,
            'wrapper_out' => $this->wrapperOut,
        ], static fn (int|string|null $value): bool => null !== $value && 0 !== $value);
    }

    /**
     * The `media_usage.tokens` entry for a provider image list: the usage
     * rides on the first image (OpenAIImageGeneration). Empty when the
     * provider reported none, which keeps the per-image price.
     *
     * @param array<mixed> $images
     *
     * @return array{tokens?: array<string, int|string>}
     */
    public static function mediaUsageOf(array $images): array
    {
        $first = $images[0] ?? null;
        $usage = \is_array($first) ? self::fromArray($first['token_usage'] ?? null) : null;

        return null !== $usage ? ['tokens' => $usage->toArray()] : [];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || [] === $data) {
            return null;
        }

        $wrapper = $data['wrapper_model'] ?? null;

        return new self(
            textIn: self::count($data['text_in'] ?? 0),
            imageIn: self::count($data['image_in'] ?? 0),
            cachedTextIn: self::count($data['cached_text_in'] ?? 0),
            cachedImageIn: self::count($data['cached_image_in'] ?? 0),
            out: self::count($data['out'] ?? 0),
            wrapperModel: \is_string($wrapper) && '' !== $wrapper ? $wrapper : null,
            wrapperIn: self::count($data['wrapper_in'] ?? 0),
            wrapperCachedIn: self::count($data['wrapper_cached_in'] ?? 0),
            wrapperOut: self::count($data['wrapper_out'] ?? 0),
        );
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
