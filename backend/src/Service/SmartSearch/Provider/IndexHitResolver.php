<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchRequest;
use App\Service\SmartSearch\SnippetBuilder;

/**
 * Turns index rows into hits. Every row is re-read from its source, which is
 * both the permission check and the guard against stale rows.
 *
 * @phpstan-import-type IndexRow from SearchIndexRepository
 * @phpstan-import-type SharedRefs from SearchIndexRepository
 */
final readonly class IndexHitResolver
{
    public function __construct(
        private SearchIndexer $indexer,
    ) {
    }

    /**
     * Kinds of the user's own rows that are requested and switched on.
     *
     * @return list<string>
     */
    public function searchableKinds(SearchRequest $request): array
    {
        $kinds = [];
        foreach ($this->indexer->sources() as $kind => $source) {
            if ($request->wants($kind) && $source->isEnabledFor($request->userId())) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * Ref ids of other people's rows the user may also search, per kind.
     *
     * @param list<string> $kinds
     *
     * @return SharedRefs
     */
    public function sharedRefs(SearchRequest $request, array $kinds): array
    {
        $shared = [];
        foreach ($kinds as $kind) {
            $refIds = $this->indexer->source($kind)?->sharedRefIds($request->userId()) ?? [];
            if ([] !== $refIds) {
                $shared[$kind] = $refIds;
            }
        }

        return $shared;
    }

    /**
     * @param list<IndexRow> $rows
     *
     * @return list<SearchHit>
     */
    public function toHits(SearchRequest $request, array $rows, string $matchedBy): array
    {
        $userId = $request->userId();
        $refsByKind = [];
        foreach ($rows as $row) {
            $refsByKind[$row['kind']][] = $row['refId'];
        }
        $resolved = [];
        foreach ($refsByKind as $kind => $refIds) {
            $source = $this->indexer->source($kind);
            $resolved[$kind] = null === $source ? [] : $source->resolve($userId, $refIds);
        }

        $hits = [];
        foreach ($rows as $row) {
            $item = $resolved[$row['kind']][$row['refId']] ?? null;
            if (null === $item) {
                continue;
            }
            $hits[] = new SearchHit(
                kind: $row['kind'],
                refId: $row['refId'],
                title: '' !== $item->title ? $item->title : $row['title'],
                route: $item->route,
                matchedBy: $matchedBy,
                subtitle: $item->subtitle,
                snippet: SnippetBuilder::build(SearchDocument::contentOf($row['title'], $row['body']), $request->fulltext->terms),
                score: $row['score'],
                sharedBy: $item->sharedBy,
            );
            if (count($hits) >= $request->limit) {
                break;
            }
        }

        return $hits;
    }
}
