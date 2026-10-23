<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Downloads one document URL to a temporary file. The caller deletes the file.
 */
interface DocumentFetcher
{
    public function fetch(string $url, string $extension): DownloadedDocument;
}
