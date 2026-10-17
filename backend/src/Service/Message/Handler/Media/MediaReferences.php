<?php

namespace App\Service\Message\Handler\Media;

use App\Service\File\ConversationFile;

/**
 * The pictures a media turn starts from: images attached to the message or
 * handed in by a multitask chain, public image URLs in its text, and the
 * conversation's picture an edit request refers to.
 */
final readonly class MediaReferences
{
    /**
     * @param list<string> $attachedImagePaths absolute paths on disk
     * @param list<string> $imageUrlsInText    public http(s) image URLs a provider can fetch
     */
    public function __construct(
        public array $attachedImagePaths,
        public array $imageUrlsInText,
        public ?ConversationFile $editSource,
    ) {
    }

    public function isPic2Pic(): bool
    {
        return [] !== $this->attachedImagePaths;
    }

    public function hasVideoReferenceImage(): bool
    {
        return $this->isPic2Pic() || [] !== $this->imageUrlsInText;
    }
}
