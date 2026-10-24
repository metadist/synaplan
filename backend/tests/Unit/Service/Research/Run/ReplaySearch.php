<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\ResearchHit;
use App\Service\Research\Run\Stage\ResearchSearch;

final class ReplaySearch implements ResearchSearch
{
    /** @var array<string, list<ResearchHit>> */
    public array $hits = [];

    /** @var list<string> */
    public array $queries = [];

    public function search(string $query, string $language, int $limit, int $userId): array
    {
        $this->queries[] = $query;

        return array_slice($this->hits[$query] ?? [], 0, $limit);
    }
}
