<?php

declare(strict_types=1);

namespace App\Bundle;

final readonly class SectionResult
{
    /**
     * @param list<string>                             $created
     * @param list<string>                             $skipped
     * @param list<array{key: string, reason: string}> $failed
     */
    public function __construct(
        public string $kind,
        public array $created = [],
        public array $skipped = [],
        public array $failed = [],
    ) {
    }

    /**
     * @return array{kind: string, created: list<string>, skipped: list<string>, failed: list<array{key: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
