<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Model;
use App\Plug\PlugKeyStore;
use App\Plug\Rerank\Adapter\HttpRerankAdapter;
use App\Plug\Rerank\Client\HttpRerankClient;
use App\Plug\Rerank\RerankCandidate;
use App\Plug\Rerank\RerankOptions;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpRerankAdapterContractTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function wireShapes(): array
    {
        return [
            'tei' => ['OpenAICompatible', 'BAAI/bge-reranker-v2-m3', 'openaicompatible/rerank.json'],
            'jina' => ['Jina', 'jina-reranker-v2-base-multilingual', 'jina/rerank.json'],
            'cohere' => ['Cohere', 'rerank-v3.5', 'cohere/rerank.json'],
            'voyage' => ['Voyage', 'rerank-2', 'voyage/rerank.json'],
        ];
    }

    #[DataProvider('wireShapes')]
    public function testMapsFixtureOrder(string $service, string $providerId, string $fixture): void
    {
        $model = $this->model($service, $providerId);
        $adapter = $this->adapter($fixture, $model, $service);
        $result = $adapter->rerank('invoice total', $this->candidates(), 2, new RerankOptions());

        $this->assertSame(['b', 'a'], array_column($result->hits, 'id'));
        $this->assertStringStartsWith('http:', $result->provider);
        $this->assertTrue($result->meter);
        $this->assertSame(347, $result->modelId);
        if ('Jina' === $service) {
            $this->assertSame(42, $result->promptTokens);
        } elseif ('Voyage' === $service) {
            $this->assertSame(17, $result->promptTokens);
        } elseif ('Cohere' === $service) {
            $this->assertSame(2, $result->requests);
        }
    }

    public function testUnreachableTeiIsUnavailable(): void
    {
        $model = $this->model('OpenAICompatible', 'BAAI/bge-reranker-v2-m3');
        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('resolveForModel')->willReturn(null);
        $adapter = new HttpRerankAdapter(
            new HttpRerankClient(new MockHttpClient()),
            $this->modelConfig($model),
            $this->models($model),
            $endpoints,
            $this->keys('jina'),
        );

        $this->assertFalse($adapter->health()->available);
        $this->assertStringContainsString('endpoint', (string) $adapter->health()->reason);
    }

    public function testChatOnlyEndpointIsUnavailableForRerank(): void
    {
        $model = $this->model('OpenAICompatible', 'BAAI/bge-reranker-v2-m3');
        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('resolveForModel')->willReturn([
            'name' => 'gateway',
            'label' => 'Chat gateway',
            'base_url' => 'http://gateway.test',
            'api_key' => '',
            'headers' => [],
            'capabilities' => ['chat', 'vectorize'],
        ]);
        $adapter = new HttpRerankAdapter(
            new HttpRerankClient(new MockHttpClient()),
            $this->modelConfig($model),
            $this->models($model),
            $endpoints,
            $this->keys('jina'),
        );

        $health = $adapter->health();
        $this->assertFalse($health->available);
        $this->assertStringContainsString('rerank capability', (string) $health->reason);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rerank capability');
        $adapter->rerank('q', $this->candidates(), 2, new RerankOptions(latencyBudgetMs: 800, maxCandidateChars: 2000));
    }

    /**
     * @return list<RerankCandidate>
     */
    private function candidates(): array
    {
        return [
            new RerankCandidate('a', 'Office hours are nine to five.', 0.9),
            new RerankCandidate('b', 'The invoice total is 120 euro.', 0.2),
            new RerankCandidate('c', 'The weather is mild.', 0.1),
        ];
    }

    private function adapter(string $fixture, Model $model, string $service): HttpRerankAdapter
    {
        $path = dirname(__DIR__, 2).'/Fixtures/rerank/'.$fixture;
        $http = new MockHttpClient([
            new MockResponse((string) file_get_contents($path), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);
        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('resolveForModel')->willReturn([
            'name' => 'tei',
            'label' => 'TEI',
            'base_url' => 'http://tei.test',
            'api_key' => '',
            'headers' => [],
            'capabilities' => ['rerank'],
        ]);

        return new HttpRerankAdapter(
            new HttpRerankClient($http),
            $this->modelConfig($model),
            $this->models($model),
            $endpoints,
            $this->keys(strtolower($service)),
        );
    }

    private function model(string $service, string $providerId): Model
    {
        $model = new Model();
        $model->setService($service);
        $model->setName($service.' rerank');
        $model->setTag('rerank');
        $model->setProviderId($providerId);
        $model->setActive(1);

        return $model;
    }

    private function modelConfig(Model $model): ModelConfigService
    {
        $config = $this->createMock(ModelConfigService::class);
        $config->method('getDefaultModel')->with('RERANK')->willReturn(347);

        return $config;
    }

    private function models(Model $model): ModelRepository
    {
        $repo = $this->createMock(ModelRepository::class);
        $repo->method('find')->willReturn($model);
        $repo->method('findByTag')->willReturn([$model]);

        return $repo;
    }

    private function keys(string $provider): PlugKeyStore
    {
        $keys = $this->createMock(PlugKeyStore::class);
        $keys->method('getKey')->willReturnCallback(
            static fn (string $name): ?string => \in_array($name, ['jina', 'cohere', 'voyage'], true) ? 'test-key' : null
        );

        return $keys;
    }
}
