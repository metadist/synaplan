<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Maps the size an image model rendered onto the size key per-image prices
 * are authored under (`json.quality_prices[quality][size]`).
 *
 * An edit leaves the size to the model, so the result keeps the photo's
 * orientation at a resolution the model picks (1448x1086, 1536x1024, …).
 * That resolution is not a price key itself; the price follows its shape.
 */
final class RenderedImageSize
{
    public const SQUARE = '1024x1024';
    public const LANDSCAPE = '1536x1024';
    public const PORTRAIT = '1024x1536';

    /** A width/height ratio within this factor of 1 is billed as square. */
    private const SQUARE_TOLERANCE = 1.1;

    /**
     * Null when the value is not a WIDTHxHEIGHT size, e.g. "auto".
     *
     * @return self::SQUARE|self::LANDSCAPE|self::PORTRAIT|null
     */
    public static function billingKey(?string $rendered): ?string
    {
        if (null === $rendered || 1 !== preg_match('/^\s*(\d+)\s*x\s*(\d+)\s*$/i', $rendered, $matches)) {
            return null;
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $ratio = $width / $height;
        if ($ratio <= self::SQUARE_TOLERANCE && $ratio >= 1 / self::SQUARE_TOLERANCE) {
            return self::SQUARE;
        }

        return $width > $height ? self::LANDSCAPE : self::PORTRAIT;
    }
}
