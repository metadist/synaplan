<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use App\Service\SmartSearch\SnippetBuilder;

/**
 * Keyword tier over the user's own index rows (chats, files, widgets,
 * assistants, saved tasks). Every hit is re-read from its source, which is
 * both the permission check and the guard against stale rows.
 */
final readonly class IndexSearchProvider implements SearchProviderInterface
{
    /** Read a few extra rows so dropping stale ones still fills the page. */
    private const OVERFETCH = 2;

    public function __construct(
        private SearchIndexRepository $repository,
        private SearchIndexer $indexer,
    ) {
    }

    public function name(): string
    {
        return 'index';
    }

    public function search(SearchRequest $request): array
    {
        $userId = $request->userId();
        $kinds = [];
        foreach ($this->indexer->sources() as $kind => $source) {
            if ($request->wants($kind) && $source->isEnabledFor($userId)) {
                $kinds[] = $kind;
            }
        }

        $rows = $this->repository->searchLexical($userId, $request->fulltext, $kinds, $request->limit * self::OVERFETCH);
        if ([] === $rows) {
            return [];
        }

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
                matchedBy: SearchHit::MATCHED_LEXICAL,
                subtitle: $item->subtitle,
                snippet: SnippetBuilder::build($row['body'], $request->fulltext->terms),
                score: $row['score'],
            );
            if (count($hits) >= $request->limit) {
                break;
            }
        }

        return $hits;
    }
}
