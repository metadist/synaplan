<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Reads a document by sending its pages to a vision model.
 *
 * A local world must not call this. The production reader only does so when
 * cloud reading is allowed and text extraction returned nothing.
 */
interface CloudDocumentReader
{
    public function read(string $absolutePath, string $extension, ?int $userId): string;
}
