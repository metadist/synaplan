<?php

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Service\RAG\VectorSearchService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test Vector Search Service
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
            ->willReturnCallback(fn(string $text, ?int $userId = null, array $options = []): array => array_fill(0, 1024, 0.2));
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
        $vectorStr = '[' . implode(',', $dummyVector) . ']';
        
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
            'created' => time()
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

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Cleanup test data
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        $conn = $em->getConnection();
        
        // Delete test vectors
        $conn->executeStatement('DELETE FROM BRAG WHERE BMID = ?', [$this->testMessageId]);
        
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

