<?php

declare(strict_types=1);

namespace App\Service\Destination;

final readonly class ShareableFile
{
    public function __construct(
        public int $fileId,
        public int $ownerId,
        public string $absolutePath,
        public string $name,
        public int $sizeBytes,
    ) {
    }
}
