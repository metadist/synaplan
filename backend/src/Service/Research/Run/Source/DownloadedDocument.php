<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * A document download. On failure `path` is empty and `reason` says why.
 */
final readonly class DownloadedDocument
{
    public function __construct(
        public bool $ok,
        public string $path,
        public string $reason,
    ) {
    }

    public static function saved(string $path): self
    {
        return new self(true, $path, '');
    }

    public static function blocked(string $reason): self
    {
        return new self(false, '', $reason);
    }
}
