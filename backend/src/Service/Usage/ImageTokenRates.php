<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Model\ImageTokenUsage;

/**
 * The per-token rates of a GPT Image model, read from the `meta` block of its
 * catalog row (USD per 1M tokens), and the cost of a request at those rates.
 *
 * OpenAI lists no separate rate for text output tokens of the image models,
 * so every output token is billed at the image output rate. A cached rate
 * missing from the row bills cached tokens at the full input rate.
 */
final readonly class ImageTokenRates
{
    private const PER_MILLION = 1_000_000;

    public function __construct(
        public float $textIn,
        public float $cachedTextIn,
        public float $imageIn,
        public float $cachedImageIn,
        public float $out,
    ) {
    }

    /**
     * Null when the row has no image output rate: the model is not billed by
     * token and keeps its per-image price.
     *
     * @param array<string, mixed> $modelJson
     */
    public static function fromModelJson(array $modelJson): ?self
    {
        $meta = \is_array($modelJson['meta'] ?? null) ? $modelJson['meta'] : [];
        $out = self::rate($meta, 'image_output_price_per_1M');
        if (null === $out || $out <= 0.0) {
            return null;
        }

        $textIn = self::rate($meta, 'text_input_price_per_1M') ?? 0.0;
        $imageIn = self::rate($meta, 'image_input_price_per_1M') ?? 0.0;

        return new self(
            textIn: $textIn,
            cachedTextIn: self::rate($meta, 'text_cached_input_price_per_1M') ?? $textIn,
            imageIn: $imageIn,
            cachedImageIn: self::rate($meta, 'image_cached_input_price_per_1M') ?? $imageIn,
            out: $out,
        );
    }

    public function inputCost(ImageTokenUsage $usage): float
    {
        $cachedText = min($usage->cachedTextIn, $usage->textIn);
        $cachedImage = min($usage->cachedImageIn, $usage->imageIn);

        return (($usage->textIn - $cachedText) * $this->textIn
            + $cachedText * $this->cachedTextIn
            + ($usage->imageIn - $cachedImage) * $this->imageIn
            + $cachedImage * $this->cachedImageIn) / self::PER_MILLION;
    }

    public function outputCost(ImageTokenUsage $usage): float
    {
        return $usage->out * $this->out / self::PER_MILLION;
    }

    /**
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        return [
            'text_input_price_per_1M' => self::format($this->textIn),
            'text_cached_input_price_per_1M' => self::format($this->cachedTextIn),
            'image_input_price_per_1M' => self::format($this->imageIn),
            'image_cached_input_price_per_1M' => self::format($this->cachedImageIn),
            'image_output_price_per_1M' => self::format($this->out),
        ];
    }

    /**
     * @param array<mixed> $meta
     */
    private static function rate(array $meta, string $key): ?float
    {
        $value = $meta[$key] ?? null;

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }

    private static function format(float $rate): string
    {
        return number_format($rate, 4, '.', '');
    }
}
