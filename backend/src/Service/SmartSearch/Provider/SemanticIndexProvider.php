<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Meaning tier over the user's own index rows: "invoice from the plumber"
 * finds the chat titled "Handwerker-Rechnung".
 */
#[AsTaggedItem(priority: 80)]
final readonly class SemanticIndexProvider implements SearchProviderInterface
{
    private const OVERFETCH = 2;

    public function __construct(
        private SearchIndexRepository $repository,
        private IndexHitResolver $resolver,
    ) {
    }

    public function name(): string
    {
        return 'semantic';
    }

    public function search(SearchRequest $request): array
    {
        $kinds = $this->resolver->searchableKinds($request);
        if ([] === $kinds) {
            return [];
        }
        $query = $request->vectors->forIndex();
        if (null === $query) {
            return [];
        }

        $rows = $this->repository->searchSemantic(
            $request->userId(),
            $query['vector'],
            $query['modelId'],
            $kinds,
            $request->limit * self::OVERFETCH,
            $request->vectors->indexMinScore(),
        );

        return [] === $rows ? [] : $this->resolver->toHits($request, $rows, SearchHit::MATCHED_SEMANTIC);
    }
}
