<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;

/**
 * Provenance + lifecycle options for an upload (hosting-partner CORE-4).
 *
 * Groups the growing set of ingest knobs an integration passes alongside the
 * binary so the upload pipeline signatures stay readable:
 *
 *  - source / originalName  provenance (where the file came from + its name).
 *  - sourceId / sourceEtag  external identity + version for in-place sync.
 *  - overwrite              replace the existing file for (user, source,
 *                           sourceId) instead of creating a duplicate row.
 *  - retainSource           when false, discard the stored binary after a
 *                           successful vectorization (the external system stays
 *                           the source of truth; Synaplan keeps text + vectors).
 */
final readonly class UploadOptions
{
    public function __construct(
        public string $source = 'web_upload',
        public ?string $originalName = null,
        public ?string $sourceId = null,
        public ?string $sourceEtag = null,
        public bool $overwrite = false,
        public bool $retainSource = true,
    ) {
    }

    /**
     * Normalize a raw provenance source to the whitelist, mirroring
     * {@see File::setSource()} so callers never persist an invalid value.
     */
    public static function normalizeSource(string $source): string
    {
        return in_array($source, File::SOURCES, true) ? $source : 'web_upload';
    }
}
