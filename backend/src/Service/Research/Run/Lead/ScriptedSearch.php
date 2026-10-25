<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Stage\ResearchHit;
use App\Service\Research\Run\Stage\ResearchSearch;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Recorded search results for the conformance suite. No network. */
#[Exclude]
final class ScriptedSearch implements ResearchSearch
{
    /** @var array<string, list<ResearchHit>> */
    public array $hits = [];

    /** @var list<string> */
    public array $queries = [];

    public function available(int $userId): bool
    {
        return true;
    }

    public function search(string $query, string $language, int $limit, int $userId): array
    {
        $this->queries[] = $query;

        return array_slice($this->hits[$query] ?? [], 0, $limit);
    }
}
