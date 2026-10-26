<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class SearchQuery
{
    public function __construct(
        public string $pointId,
        public string $query,
        public string $scope,
        public int $results,
    ) {
    }

    /**
     * @return array{point: string, q: string, scope: string, results: int}
     */
    public function toArray(): array
    {
        return [
            'point' => $this->pointId,
            'q' => $this->query,
            'scope' => $this->scope,
            'results' => $this->results,
        ];
    }
}
