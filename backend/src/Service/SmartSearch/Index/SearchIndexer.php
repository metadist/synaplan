<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Repository\SearchIndexRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Keeps BSEARCHINDEX in step with the sources of truth.
 */
final readonly class SearchIndexer
{
    /** @var array<string, SearchDocumentSourceInterface> */
    private array $sources;

    /**
     * @param iterable<SearchDocumentSourceInterface> $sources
     */
    public function __construct(
        private SearchIndexRepository $repository,
        #[AutowireIterator('app.smart_search.source')]
        iterable $sources,
    ) {
        $byKind = [];
        foreach ($sources as $source) {
            $byKind[$source->kind()] = $source;
        }
        $this->sources = $byKind;
    }

    /** @return array<string, SearchDocumentSourceInterface> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function source(string $kind): ?SearchDocumentSourceInterface
    {
        return $this->sources[$kind] ?? null;
    }

    /**
     * Refreshes one item: upserts the current document, or (when
     * `$deleteWhenMissing`) removes the row when the item is gone or no
     * longer searchable.
     *
     * @return bool true when a document was written
     */
    public function refresh(string $kind, int $userId, string $refId, bool $deleteWhenMissing = true): bool
    {
        $document = $this->requireSource($kind)->build($userId, $refId);
        if (null === $document) {
            if ($deleteWhenMissing) {
                $this->repository->delete($userId, $kind, $refId);
            }

            return false;
        }
        $this->repository->upsert($document);

        return true;
    }

    /** Drops one row without reading the source (the item was deleted). */
    public function remove(string $kind, int $userId, string $refId): void
    {
        $this->requireSource($kind);
        $this->repository->delete($userId, $kind, $refId);
    }

    private function requireSource(string $kind): SearchDocumentSourceInterface
    {
        return $this->source($kind)
            ?? throw new \InvalidArgumentException(sprintf('Unknown Smart Search kind "%s" (known: %s)', $kind, implode(', ', array_keys($this->sources))));
    }

    /**
     * Rebuilds every kind of one user and drops rows whose item is gone.
     *
     * @return int number of documents written
     */
    public function reindexUser(int $userId): int
    {
        $written = 0;
        foreach ($this->sources as $kind => $source) {
            $kept = [];
            foreach ($source->allForUser($userId) as $document) {
                $this->repository->upsert($document);
                $kept[] = $document->refId;
                ++$written;
            }
            $this->repository->deleteMissing($userId, $kind, $kept);
        }

        return $written;
    }

    /**
     * Rewrites the shared catalog rows of one kind (BUSERID = 0).
     *
     * @param list<SearchDocument> $documents
     */
    public function replaceCatalog(string $kind, array $documents): int
    {
        $kept = [];
        foreach ($documents as $document) {
            $this->repository->upsert($document);
            $kept[] = $document->refId;
        }
        $this->repository->deleteMissing(SettingsCatalog::CATALOG_USER_ID, $kind, $kept);

        return count($kept);
    }
}
