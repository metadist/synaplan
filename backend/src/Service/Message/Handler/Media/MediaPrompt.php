<?php

namespace App\Service\Message\Handler\Media;

/**
 * The prompt a media turn renders, the media type the extraction asked for
 * (null when it could not tell) and the pictures the prompt was written for.
 */
final readonly class MediaPrompt
{
    public function __construct(
        public string $text,
        public ?string $mediaType,
        public MediaReferences $references,
    ) {
    }
}
