<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Keyword tier over the user's own index rows (chats, files, widgets,
 * assistants, saved tasks) and the chats and files shared with them.
 */
#[AsTaggedItem(priority: 100)]
final readonly class IndexSearchProvider implements SearchProviderInterface
{
    /** Read a few extra rows so dropping stale ones still fills the page. */
    private const OVERFETCH = 2;

    public function __construct(
        private SearchIndexRepository $repository,
        private IndexHitResolver $resolver,
    ) {
    }

    public function name(): string
    {
        return 'index';
    }

    public function search(SearchRequest $request): array
    {
        $kinds = $this->resolver->searchableKinds($request);
        $rows = $this->repository->searchLexical(
            $request->userId(),
            $request->fulltext,
            $kinds,
            $request->limit * self::OVERFETCH,
            $this->resolver->sharedRefs($request, $kinds),
        );

        return [] === $rows ? [] : $this->resolver->toHits($request, $rows, SearchHit::MATCHED_LEXICAL);
    }
}
