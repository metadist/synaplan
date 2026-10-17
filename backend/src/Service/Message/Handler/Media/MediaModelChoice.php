<?php

namespace App\Service\Message\Handler\Media;

/**
 * The model a media turn picked and why: an Again pick, a task prompt's
 * pinned model or the install's default for the media type. Provider and
 * name are known only for a picked model until the model row is read.
 */
final readonly class MediaModelChoice
{
    public function __construct(
        public ?int $modelId,
        public string $mediaType,
        public ?string $provider,
        public ?string $modelName,
        public bool $isSlashCommand,
        public MediaReferences $references,
    ) {
    }
}
