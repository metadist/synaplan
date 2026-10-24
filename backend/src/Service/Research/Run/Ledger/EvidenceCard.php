<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class EvidenceCard
{
    /**
     * @param list<string>     $passages
     * @param list<QuoteMatch> $quotes
     */
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $pointId,
        public string $fact,
        public array $passages,
        public array $quotes,
        public string $confidence = 'medium',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->sourceId,
            'point' => $this->pointId,
            'fact' => $this->fact,
            'passages' => $this->passages,
            'quotes' => array_map(static fn (QuoteMatch $quote): array => $quote->toArray(), $this->quotes),
            'confidence' => $this->confidence,
        ];
    }
}
