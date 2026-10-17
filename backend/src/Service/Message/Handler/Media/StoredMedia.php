<?php

namespace App\Service\Message\Handler\Media;

/**
 * A generated file as the turn kept it: the path in the upload tree, the URL
 * the chat serves it from and, for a video, its poster.
 */
final readonly class StoredMedia
{
    public function __construct(
        public string $localPath,
        public string $displayUrl,
        public ?string $thumbnailPath,
    ) {
    }
}
