<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\File;
use App\Service\Document\Tool\DocumentToolResult;

final readonly class DocumentEditResult
{
    /**
     * @param list<DocumentToolResult> $steps
     * @param array<string, mixed>     $usage
     */
    public function __construct(
        public string $content,
        public ?File $file,
        public array $steps,
        public array $usage = [],
        public ?int $version = null,
        public bool $fidelityLossy = false,
    ) {
    }
}
