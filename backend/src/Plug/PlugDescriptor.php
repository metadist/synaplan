<?php

declare(strict_types=1);

namespace App\Plug;

/**
 * Static description of one plug adapter (label, docs, required settings).
 */
final readonly class PlugDescriptor
{
    /**
     * @param list<string> $requiredSettings BCONFIG keys this adapter reads
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $docsUrl,
        public array $requiredSettings,
        public string $sovereignty,
    ) {
    }
}
