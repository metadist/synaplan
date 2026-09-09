<?php

declare(strict_types=1);

namespace Plugin\SerperSearch\Plug;

use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;

/**
 * Maps a Serper `/search` payload onto the shared web-search result shape.
 * Pure, so the adapter's contract can be tested on a recorded fixture without
 * a live call.
 */
final class SerperSearchResultMapper
{
    /**
     * @param array<int|string, mixed> $payload
     */
    public static function map(string $query, array $payload, string $providerKey): SearchResultSet
    {
        $organic = $payload['organic'] ?? [];
        $results = [];
        if (is_array($organic)) {
            foreach ($organic as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = is_string($row['link'] ?? null) ? $row['link'] : '';
                if ('' === $url) {
                    continue;
                }
                $date = $row['date'] ?? null;
                $results[] = new SearchResult(
                    title: is_string($row['title'] ?? null) ? $row['title'] : '',
                    url: $url,
                    description: is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
                    publishedAt: is_string($date) ? $date : null,
                );
            }
        }

        return SearchResultSet::fromResults($query, $results, [
            'provider' => $providerKey,
            'total' => count($results),
        ]);
    }
}
