<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final class ToolBudget
{
    public function __construct(
        private int $searchesLeft,
        private int $sourcesLeft,
    ) {
    }

    public function searchesLeft(): int
    {
        return $this->searchesLeft;
    }

    public function sourcesLeft(): int
    {
        return $this->sourcesLeft;
    }

    /**
     * @return array{error: string, left: array{searches: int, sources: int}}|null
     */
    public function takeSearch(): ?array
    {
        if ($this->searchesLeft <= 0) {
            return $this->spent();
        }

        --$this->searchesLeft;

        return null;
    }

    /**
     * @return array{error: string, left: array{searches: int, sources: int}}
     */
    private function spent(): array
    {
        return [
            'error' => 'budget_used',
            'left' => [
                'searches' => $this->searchesLeft,
                'sources' => $this->sourcesLeft,
            ],
        ];
    }
}
