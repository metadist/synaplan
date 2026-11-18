<?php

declare(strict_types=1);

namespace App\Service\Research\Export;

/**
 * One downloadable file of a reply: its bytes, its media type and the name
 * it is saved under, with a plain ASCII name for clients that need one.
 */
final readonly class ExportedReply
{
    public function __construct(
        public string $content,
        public string $contentType,
        public string $fileName,
        public string $asciiFileName,
    ) {
    }
}
