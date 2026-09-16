<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

final readonly class DocsPage
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $section,
        public string $description,
        public string $url,
        public string $rawUrl,
        public string $sha256,
        public int $bytes,
        public string $updatedAt,
    ) {
    }
}
