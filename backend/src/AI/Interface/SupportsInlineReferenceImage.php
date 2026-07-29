<?php

declare(strict_types=1);

namespace App\AI\Interface;

/**
 * A video provider that accepts an image-to-video reference frame as inline
 * bytes (a base64 `data:` URI) instead of only as a public http(s) URL.
 *
 * Most i2v providers (Higgsfield, Veo) FETCH the source frame themselves, so
 * the attached file has to be republished at an internet-reachable URL first —
 * which is impossible when `APP_URL` points at localhost, the default in local
 * dev. Providers implementing this marker are handed the local upload path
 * instead and inline it themselves, so image-to-video works without a tunnel.
 *
 * Implementations MUST accept a local filesystem path in the reference-image
 * option (`image_url` / `images`) and convert it before sending.
 */
interface SupportsInlineReferenceImage extends VideoGenerationProviderInterface
{
}
