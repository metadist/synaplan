<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown when the current message carries image attachments but none of them
 * could be prepared for the vision model (unreadable bytes, or too large even
 * after downscaling). Without this, the request would silently proceed
 * text-only and the model would answer "I don't see an image" — a confusing
 * hallucination instead of an actionable error.
 */
final class VisionImageUnprocessableException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'The attached image could not be prepared for the AI model — it may be too large or unreadable. Please try again with a smaller image (JPEG, PNG, GIF or WebP).',
            0,
            $previous
        );
    }
}
