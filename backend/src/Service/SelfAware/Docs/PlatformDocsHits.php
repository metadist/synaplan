<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

final readonly class PlatformDocsHits
{
    /**
     * @param list<PlatformDocsHit> $hits
     */
    public function __construct(
        public array $hits,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->hits;
    }

    /**
     * @return list<array{slug: string, title: string, url: string}>
     */
    public function toClientList(): array
    {
        $rows = [];
        foreach ($this->hits as $hit) {
            $rows[] = $hit->toClient();
        }

        return $rows;
    }
}
