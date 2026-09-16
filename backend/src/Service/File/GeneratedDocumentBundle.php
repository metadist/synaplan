<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;

/**
 * Result of persisting an officemaker envelope: the editable source and,
 * when {@code BEXPORT=pdf} succeeded, the PDF the user asked for.
 */
final readonly class GeneratedDocumentBundle
{
    public function __construct(
        public File $source,
        public ?File $export = null,
    ) {
    }

    public function primary(): File
    {
        return $this->export ?? $this->source;
    }

    /**
     * @return list<File>
     */
    public function files(): array
    {
        return null === $this->export ? [$this->source] : [$this->source, $this->export];
    }
}
