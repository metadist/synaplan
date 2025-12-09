<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\TokenService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RagControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function getAuthToken(): string
    {
        if ($this->token) {
            return $this->token;
        }

        $userRepository = $this->client->getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            $this->fail('Test user not found');
        }

        // Generate access token using TokenService
        $this->token = $this->authenticateClient($this->client, $user);

        return $this->token;
    }

    public function testSearchRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test query',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSearchRequiresQuery(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSearchWithEmptyQuery(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => '   ',
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchWithValidQuery(): void
    {
        $this->markTestSkipped('RAG search requires vector DB setup and test data - needs proper integration test infrastructure');
    }

    public function testSearchWithCustomLimit(): void
    {
        $this->markTestSkipped('RAG search requires vector DB setup and test data - needs proper integration test infrastructure');
    }

    public function testSearchLimitBoundaries(): void
    {
        $this->markTestSkipped('RAG search requires vector DB setup and test data - needs proper integration test infrastructure');
    }

    public function testSearchWithMinScore(): void
    {
        $this->markTestSkipped('RAG search requires vector DB setup and test data - needs proper integration test infrastructure');
    }

    public function testSearchWithGroupKey(): void
    {
        $this->markTestSkipped('RAG search requires vector DB setup and test data - needs proper integration test infrastructure');
    }

    public function testSearchOnlyAcceptsPostMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testFindSimilarRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/rag/similar/1');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testFindSimilarWithValidChunkId(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/similar/1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('source_chunk_id', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total_results', $data);
    }

    public function testFindSimilarWithCustomLimit(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/similar/1?limit=20', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testFindSimilarOnlyAcceptsGetMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/similar/1', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testStatsRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/rag/stats');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testStatsReturnsUserStatistics(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('stats', $data);
    }

    public function testStatsOnlyAcceptsGetMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/stats', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }
}
