<?php

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test Vector Search Service.
 */
class VectorSearchServiceTest extends KernelTestCase
{
    private const NON_EXISTENT_USER_ID = 999999;

    private VectorSearchService $vectorSearchService;
    private int $testUserId = 0;
    private int $testMessageId = 0;
    private int $testModelId = 0;
    private int $userModelConfigId = 0;
    private int $fallbackModelConfigId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = static::getContainer();

        $aiFacadeMock = $this->createMock(AiFacade::class);
        $aiFacadeMock->method('embed')
            ->willReturnCallback(fn (string $text, ?int $userId = null, array $options = []): array => [
                'embedding' => array_fill(0, 1024, 0.2),
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ]);
        $container->set(AiFacade::class, $aiFacadeMock);

        $this->vectorSearchService = $container->get(VectorSearchService::class);

        // Create test user + data
        $this->testUserId = $this->createTestUser();
        $this->testModelId = $this->createTestEmbeddingModel();
        $this->userModelConfigId = $this->configureDefaultVectorizeModel($this->testUserId);
        $this->fallbackModelConfigId = $this->configureDefaultVectorizeModel(self::NON_EXISTENT_USER_ID);
        $this->testMessageId = $this->createTestVectorData();
    }

    private function createTestUser(): int
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = new \App\Entity\User();
        $user->setCreated((string) time());
        $user->setType('WEB');
        $user->setMail(sprintf('vector-test-%s@example.test', uniqid('', true)));
        $user->setPw(password_hash('vector-test', PASSWORD_BCRYPT) ?: 'vector-test');
        $user->setProviderId('vector-test');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setUserDetails([]);
        $user->setPaymentDetails([]);

        $em->persist($user);
        $em->flush();

        return (int) $user->getId();
    }

    private function createTestEmbeddingModel(): int
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $model = new \App\Entity\Model();
        $model->setService('test');
        $model->setName('Test Embedding Model');
        $model->setTag('vector-test');
        $model->setSelectable(0);
        $model->setProviderId('test-embedding');
        $model->setPriceIn(0);
        $model->setPriceOut(0);
        $model->setJson([
            'capability' => 'VECTORIZE',
            'dimensions' => 1024,
        ]);

        $em->persist($model);
        $em->flush();

        return (int) $model->getId();
    }

    private function configureDefaultVectorizeModel(int $ownerId): int
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $config = new \App\Entity\Config();
        $config->setOwnerId($ownerId);
        $config->setGroup('DEFAULTMODEL');
        $config->setSetting('VECTORIZE');
        $config->setValue((string) $this->testModelId);

        $em->persist($config);
        $em->flush();

        return (int) $config->getId();
    }

    private function createTestVectorData(): int
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // Create test message
        $message = new \App\Entity\Message();
        $message->setUserId($this->testUserId);
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setText('Test document about machine learning and AI');
        $message->setFile(1);
        $message->setFilePath('test/ml-doc.txt');
        $message->setFileType(0);

        $em->persist($message);
        $em->flush();

        // Insert test vector (using dummy embedding)
        $conn = $em->getConnection();
        $dummyVector = array_fill(0, 1024, 0.1);
        $vectorStr = '['.implode(',', $dummyVector).']';

        $sql = 'INSERT INTO BRAG (BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT, BEMBED, BCREATED) 
                VALUES (:uid, :mid, :gkey, :ftype, :start, :end, :text, VEC_FromText(:vec), :created)';

        $conn->executeStatement($sql, [
            'uid' => $this->testUserId,
            'mid' => $message->getId(),
            'gkey' => 'TEST',
            'ftype' => 0,
            'start' => 1,
            'end' => 10,
            'text' => 'Machine learning is a subset of artificial intelligence',
            'vec' => $vectorStr,
            'created' => time(),
        ]);

        return $message->getId();
    }

    public function testSemanticSearch(): void
    {
        $results = $this->vectorSearchService->semanticSearch(
            'Tell me about machine learning',
            $this->testUserId,
            5
        );

        $this->assertIsArray($results);
        // Note: Real search requires actual embeddings from AI model
        // This test just verifies the query runs without error
    }

    public function testSemanticSearchWithGroupFilter(): void
    {
        $results = $this->vectorSearchService->semanticSearch(
            'artificial intelligence',
            $this->testUserId,
            'TEST', // group
            5 // limit as int
        );

        $this->assertIsArray($results);
    }

    public function testSemanticSearchReturnsDistance(): void
    {
        $results = $this->vectorSearchService->semanticSearch(
            'AI and ML',
            $this->testUserId,
            10
        );

        // If results exist, they should have distance field
        if (count($results) > 0) {
            $this->assertArrayHasKey('distance', $results[0]);
            $this->assertIsNumeric($results[0]['distance']);
        }

        $this->assertIsArray($results);
    }

    public function testFindSimilar(): void
    {
        $results = $this->vectorSearchService->findSimilar(
            $this->testMessageId,
            $this->testUserId,
            5
        );

        $this->assertIsArray($results);
    }

    public function testSemanticSearchWithNonExistentUser(): void
    {
        $results = $this->vectorSearchService->semanticSearch(
            'test query',
            self::NON_EXISTENT_USER_ID,
            5
        );

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testFindSimilarWithNonExistentMessage(): void
    {
        $results = $this->vectorSearchService->findSimilar(
            999999,
            $this->testUserId,
            5
        );

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Regression test for issues #346 / #755.
     *
     * Before the fix, a provider that returns embeddings wider than the
     * documents collection's fixed 1024 dim (OpenAI `text-embedding-3-small`
     * at 1536, `-large` at 3072) caused `VectorSearchService::semanticSearch`
     * to hand a 1536-dim query vector to `VectorStorageFacade::search`. The
     * storage layer either rejected it (Qdrant) or compared it against
     * 1024-dim stored vectors and returned NULL distances (MariaDB
     * `VEC_DISTANCE_COSINE`), producing zero search results for what should
     * be a perfect match.
     *
     * Asserts that:
     *   1. The vector handed to the storage facade is always 1024 floats
     *      regardless of the provider's native dimension.
     *   2. Wider vectors are truncated (first N elements preserved).
     *   3. Same normalisation is applied to the precomputed-vector path
     *      (`semanticSearchByVector`) so callers cannot accidentally bypass
     *      the fix by skipping the embed round-trip.
     */
    public function testQueryVectorIsNormalizedToCollectionDimension(): void
    {
        // Reboot the kernel so we can install fresh mocks BEFORE the
        // AiFacade has been resolved (the setUp() mock already counts as
        // "initialised", and Symfony's TestContainer refuses to replace
        // an already-resolved service).
        static::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();

        // Return a 1536-dim vector with a recognisable signature so we can
        // verify truncation rather than padding.
        $aiFacadeMock = $this->createMock(AiFacade::class);
        $aiFacadeMock->method('embed')
            ->willReturnCallback(static function (): array {
                $vector = [];
                for ($i = 0; $i < 1536; ++$i) {
                    $vector[] = ($i < 1024) ? 0.1 : 9.9; // sentinel values >1024
                }

                return [
                    'embedding' => $vector,
                    'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
                ];
            });
        $container->set(AiFacade::class, $aiFacadeMock);

        /** @var list<SearchQuery> $capturedQueries */
        $capturedQueries = [];
        $vectorStorageMock = $this->createMock(VectorStorageFacade::class);
        $vectorStorageMock->method('search')
            ->willReturnCallback(static function (SearchQuery $q) use (&$capturedQueries): array {
                $capturedQueries[] = $q;

                return [];
            });
        $vectorStorageMock->method('getProviderName')->willReturn('test-storage');
        $container->set(VectorStorageFacade::class, $vectorStorageMock);

        // Re-resolve the service so it picks up the freshly-installed mocks.
        $service = $container->get(VectorSearchService::class);

        $service->semanticSearch('any query', $this->testUserId);

        $this->assertCount(1, $capturedQueries, 'exactly one storage search per call');
        /** @var SearchQuery $firstQuery */
        $firstQuery = $capturedQueries[0];
        $vector = $firstQuery->vector;
        $this->assertCount(1024, $vector, 'query vector must be coerced to the collection width');
        $this->assertEqualsWithDelta(0.1, $vector[0], 1e-9, 'first sentinel preserved');
        $this->assertEqualsWithDelta(0.1, $vector[1023], 1e-9, 'truncation took the leading 1024 floats, not the trailing ones');

        // Same guarantee on the precomputed-vector path.
        /** @var list<SearchQuery> $capturedQueries */
        $capturedQueries = [];
        $widePrecomputedVector = array_fill(0, 1536, 0.5);
        $service->semanticSearchByVector($this->testUserId, $widePrecomputedVector);
        $this->assertCount(1, $capturedQueries);
        /** @var SearchQuery $secondQuery */
        $secondQuery = $capturedQueries[0];
        $this->assertCount(
            1024,
            $secondQuery->vector,
            'precomputed-vector path must apply the same normalisation'
        );
    }

    /**
     * Regression test for issues #346 / #755 — narrower providers must be
     * zero-padded so the storage layer does not reject the query.
     */
    public function testQueryVectorIsPaddedWhenProviderReturnsNarrower(): void
    {
        // Same kernel reboot pattern as the truncation test above.
        static::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();

        $aiFacadeMock = $this->createMock(AiFacade::class);
        $aiFacadeMock->method('embed')->willReturn([
            'embedding' => array_fill(0, 768, 0.5),
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
        $container->set(AiFacade::class, $aiFacadeMock);

        /** @var list<SearchQuery> $capturedQueries */
        $capturedQueries = [];
        $vectorStorageMock = $this->createMock(VectorStorageFacade::class);
        $vectorStorageMock->method('search')
            ->willReturnCallback(static function (SearchQuery $q) use (&$capturedQueries): array {
                $capturedQueries[] = $q;

                return [];
            });
        $vectorStorageMock->method('getProviderName')->willReturn('test-storage');
        $container->set(VectorStorageFacade::class, $vectorStorageMock);

        $service = $container->get(VectorSearchService::class);

        $service->semanticSearch('any query', $this->testUserId);

        $this->assertCount(1, $capturedQueries);
        /** @var SearchQuery $captured */
        $captured = $capturedQueries[0];
        $vector = $captured->vector;
        $this->assertCount(1024, $vector);
        $this->assertEqualsWithDelta(0.5, $vector[0], 1e-9);
        $this->assertEqualsWithDelta(0.5, $vector[767], 1e-9, 'real values preserved');
        $this->assertEqualsWithDelta(0.0, $vector[768], 1e-9, 'tail padded with zeros');
        $this->assertEqualsWithDelta(0.0, $vector[1023], 1e-9, 'tail padded all the way to the collection width');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Cleanup test data
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        $conn = $em->getConnection();

        // Delete test vectors
        $conn->executeStatement('DELETE FROM BRAG WHERE BMID = ?', [$this->testMessageId]);

        // Delete usage log entries created by recordUsage (FK on BMODEL_ID)
        if ($this->testUserId > 0) {
            $conn->executeStatement('DELETE FROM BUSELOG WHERE BUSERID = ?', [$this->testUserId]);
        }

        $entitiesToRemove = [];

        // Delete test message
        $message = $this->testMessageId > 0 ? $em->find(\App\Entity\Message::class, $this->testMessageId) : null;
        if ($message) {
            $entitiesToRemove[] = $message;
        }

        // Delete configs
        if ($this->userModelConfigId > 0) {
            $config = $em->find(\App\Entity\Config::class, $this->userModelConfigId);
            if ($config) {
                $entitiesToRemove[] = $config;
            }
        }

        if ($this->fallbackModelConfigId > 0) {
            $config = $em->find(\App\Entity\Config::class, $this->fallbackModelConfigId);
            if ($config) {
                $entitiesToRemove[] = $config;
            }
        }

        // Delete test model
        if ($this->testModelId > 0) {
            $model = $em->find(\App\Entity\Model::class, $this->testModelId);
            if ($model) {
                $entitiesToRemove[] = $model;
            }
        }

        // Delete test user
        $user = $this->testUserId > 0 ? $em->find(\App\Entity\User::class, $this->testUserId) : null;
        if ($user) {
            $entitiesToRemove[] = $user;
        }

        foreach ($entitiesToRemove as $entity) {
            $em->remove($entity);
        }

        if (!empty($entitiesToRemove)) {
            $em->flush();
        }
    }
}
