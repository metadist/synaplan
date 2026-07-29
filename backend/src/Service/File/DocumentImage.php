<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * One image the model may place into a generated document, described exactly
 * the way {@see DocumentImageReferenceResolver} will accept it back.
 */
final readonly class DocumentImage
{
    public const ORIGIN_ATTACHED = 'attached';
    public const ORIGIN_GENERATED = 'generated';
    public const ORIGIN_UPLOADED = 'uploaded';

    /**
     * @param string $reference marker payload, e.g. `file:42` or `attached:1`
     * @param string $name      display name shown to the model
     * @param string $origin    one of the ORIGIN_* constants
     * @param string $path      absolute, validated path inside the upload dir
     */
    public function __construct(
        public string $reference,
        public string $name,
        public string $origin,
        public string $path,
    ) {
    }

    public function marker(): string
    {
        return '{{IMAGE:'.$this->reference.'}}';
    }
}
