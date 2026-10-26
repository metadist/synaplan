<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

interface ResearchSearch
{
    /** False when no provider is set up for this person, so nothing could be searched. */
    public function available(int $userId): bool;

    /**
     * @return list<ResearchHit>
     */
    public function search(string $query, string $language, int $limit, int $userId): array;
}
