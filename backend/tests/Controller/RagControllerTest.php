<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class RagControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function getAuthToken(): string
    {
        if ($this->token) {
            return $this->token;
        }

        $userRepository = static::getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            $this->fail('Test user not found');
        }

        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->token = $jwtManager->create($user);

        return $this->token;
    }

    public function testSearchRequiresAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test query'
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSearchRequiresQuery(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSearchWithEmptyQuery(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => '   '
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchWithValidQuery(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'machine learning'
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('query', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total_results', $data);
        $this->assertArrayHasKey('search_time_ms', $data);
    }

    public function testSearchWithCustomLimit(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test',
            'limit' => 5
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('parameters', $data);
        $this->assertEquals(5, $data['parameters']['limit']);
    }

    public function testSearchLimitBoundaries(): void
    {
        $token = $this->getAuthToken();

        // Test max limit (should cap at 50)
        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test',
            'limit' => 100
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(50, $data['parameters']['limit']);

        // Test min limit (should be at least 1)
        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test',
            'limit' => -5
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(1, $data['parameters']['limit']);
    }

    public function testSearchWithMinScore(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test',
            'min_score' => 0.8
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals(0.8, $data['parameters']['min_score']);
    }

    public function testSearchWithGroupKey(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/search', [
            'query' => 'test',
            'group_key' => 'project_docs'
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals('project_docs', $data['parameters']['group_key']);
    }

    public function testSearchOnlyAcceptsPostMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testFindSimilarRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/rag/similar/1');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testFindSimilarWithValidChunkId(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/similar/1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
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
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testFindSimilarOnlyAcceptsGetMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->jsonRequest('POST', '/api/v1/rag/similar/1', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/rag/stats');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testStatsReturnsUserStatistics(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/rag/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
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
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }
}

