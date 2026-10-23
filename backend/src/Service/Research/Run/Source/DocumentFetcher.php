<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Downloads one document URL to a temporary file. The caller deletes the file.
 *
 * `$timeoutSeconds` caps the whole download, redirects included; an
 * implementation may lower it further but never raise it.
 */
interface DocumentFetcher
{
    public function fetch(string $url, string $extension, int $timeoutSeconds = GuardedDocumentDownload::TIMEOUT_SECONDS): DownloadedDocument;
}
