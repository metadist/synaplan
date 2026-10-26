<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

interface SpikeSources
{
    /**
     * @return list<SearchHit>
     */
    public function search(string $query, int $limit, int $userId): array;

    public function read(string $url): PageBody;
}
