<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One ranked list that Smart Search fuses into the answer. A provider only
 * returns what the requesting user may open.
 */
#[AutoconfigureTag('app.smart_search.provider')]
interface SearchProviderInterface
{
    /** Stable name, reported in `degraded` when the provider was skipped or failed. */
    public function name(): string;

    /** @return list<SearchHit> best first */
    public function search(SearchRequest $request): array;
}
