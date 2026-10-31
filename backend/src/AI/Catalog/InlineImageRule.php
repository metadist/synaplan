<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * Which image inputs a provider accepts in a chat turn.
 */
enum InlineImageRule: string
{
    /** Data URLs of any type and public links. */
    case Any = 'any';

    /** Inline base64 PNG or JPEG only; other formats are transcoded, links are refused. */
    case PngJpegOnly = 'png_jpeg_only';
}
