<?php

namespace App\Tests\Service;

use App\Service\RAG\VectorSearchService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test Vector Search Service
 */
class VectorSearchServiceTest extends KernelTestCase
{
    private VectorSearchService $vectorSearchService;
    private int $testUserId = 1;
    private int $testMessageId;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        
        $container = static::getContainer();
        $this->vectorSearchService = $container->get(VectorSearchService::class);
        
        // Create test data
        $this->testMessageId = $this->createTestVectorData();
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
        $dummyVector = array_fill(0, 1024, 0.1); // 1024-dim vector
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
            999999,
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
        
        // Delete test message
        $message = $em->find(\App\Entity\Message::class, $this->testMessageId);
        if ($message) {
            $em->remove($message);
            $em->flush();
        }
    }
}

