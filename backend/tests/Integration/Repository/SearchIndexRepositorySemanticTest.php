<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchIndexRepositorySemanticTest extends KernelTestCase
{
    private const USER_ID = 987_654_321;
    private const MODEL_ID = 424_242;
    private const OTHER_MODEL_ID = 424_243;

    private SearchIndexRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(SearchIndexRepository::class);
    }

    public function testNearestRowsComeFirstAndStayInTheirModelSpace(): void
    {
        $this->store('chat', '1', 'Plumber invoice', self::axis(0), self::MODEL_ID);
        $this->store('chat', '2', 'Kitchen plans', self::mix(0, 1), self::MODEL_ID);
        $this->store('chat', '3', 'Holiday photos', self::axis(2), self::MODEL_ID);
        $this->store('file', '4', 'Other model', self::axis(0), self::OTHER_MODEL_ID);

        $rows = $this->repository->searchSemantic(self::USER_ID, self::axis(0), self::MODEL_ID, ['chat', 'file'], 10, 0.5);

        self::assertSame(['1', '2'], array_column($rows, 'refId'));
        self::assertEqualsWithDelta(1.0, $rows[0]['score'], 0.0001);
        self::assertEqualsWithDelta(sqrt(0.5), $rows[1]['score'], 0.0001);
    }

    public function testAChangedTextNeedsANewVectorAndAStaleEmbedIsDropped(): void
    {
        $this->store('chat', '7', 'Draft', self::axis(0), self::MODEL_ID);
        self::assertSame([], $this->repository->findPendingEmbeddings(self::USER_ID, self::MODEL_ID, 10));

        $this->repository->upsert(new SearchDocument(self::USER_ID, 'chat', '7', 'Final', '', time()));
        $pending = $this->repository->findPendingEmbeddings(self::USER_ID, self::MODEL_ID, 10);
        self::assertSame(['Final'], array_column($pending, 'title'));

        // A vector computed for the old text must not land on the new one.
        $this->repository->storeEmbedding($pending[0]['id'], sha1("Draft\n"), self::axis(0), self::MODEL_ID);
        self::assertCount(1, $this->repository->findPendingEmbeddings(self::USER_ID, self::MODEL_ID, 10));

        // A model switch makes every row pending again.
        self::assertCount(1, $this->repository->findPendingEmbeddings(self::USER_ID, self::OTHER_MODEL_ID, 10));
    }

    public function testAverageSimilarityDescribesTheNoiseLevel(): void
    {
        self::assertNull($this->repository->averageSimilarity(self::USER_ID, self::axis(0), self::MODEL_ID));

        $this->store('setting', 'A', 'A', self::axis(0), self::MODEL_ID);
        $this->store('setting', 'B', 'B', self::axis(1), self::MODEL_ID);

        self::assertEqualsWithDelta(0.5, (float) $this->repository->averageSimilarity(self::USER_ID, self::axis(0), self::MODEL_ID), 0.0001);
    }

    /**
     * @param list<float> $vector
     */
    private function store(string $kind, string $refId, string $title, array $vector, int $modelId): void
    {
        $document = new SearchDocument(self::USER_ID, $kind, $refId, $title, '', time());
        $this->repository->upsert($document);
        $row = $this->repository->findPendingEmbeddings(self::USER_ID, $modelId, 50);
        foreach ($row as $pending) {
            if ($pending['title'] === $title) {
                $this->repository->storeEmbedding($pending['id'], $pending['hash'], $vector, $modelId);
            }
        }
    }

    /** @return list<float> unit vector along one dimension */
    private static function axis(int $dimension): array
    {
        $vector = array_fill(0, SearchEmbeddingModel::DIMENSION, 0.0);
        $vector[$dimension] = 1.0;

        return $vector;
    }

    /** @return list<float> unit vector halfway between two dimensions */
    private static function mix(int $a, int $b): array
    {
        $vector = array_fill(0, SearchEmbeddingModel::DIMENSION, 0.0);
        $vector[$a] = $vector[$b] = sqrt(0.5);

        return $vector;
    }
}
