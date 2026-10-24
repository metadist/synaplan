<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

interface ResearchSearch
{
    /**
     * @return list<ResearchHit>
     */
    public function search(string $query, string $language, int $limit, int $userId): array;
}
