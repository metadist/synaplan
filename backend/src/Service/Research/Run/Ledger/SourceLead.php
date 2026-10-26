<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class SourceLead
{
    public function __construct(
        public string $sourceId,
        public string $fromId,
        public string $pointId,
        public string $anchor,
    ) {
    }

    /**
     * @return array{source: string, from: string, point: string, anchor: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceId,
            'from' => $this->fromId,
            'point' => $this->pointId,
            'anchor' => $this->anchor,
        ];
    }
}
