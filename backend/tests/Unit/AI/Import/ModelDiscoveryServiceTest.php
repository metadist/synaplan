<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Import;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Import\ModelDiscoveryService;
use App\AI\Import\ModelTagGuesser;
use App\AI\Import\UnknownImportSourceException;
use App\AI\Service\OllamaModelInventory;
use App\Entity\Model;
use App\Repository\ModelRepository;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryServiceTest extends TestCase
{
    public function testOpenAiCompatibleListingMapsGuessesAndExists(): void
    {
        $ids = $this->fixtureIds('models-vllm.json');

        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('getEndpoint')->willReturn($this->endpointRow());
        $endpoints->method('listModelIds')->willReturn(['ok' => true, 'ids' => $ids]);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findByServiceIndexedByProviderId')
            ->willReturn(['BAAI/bge-m3' => new Model()]);

        $result = $this->service($endpoints, $this->createMock(OllamaModelInventory::class), $models)
            ->discover('openai_compatible:vllm-lab');

        self::assertTrue($result->ok);
        self::assertCount(5, $result->models);

        $byId = [];
        foreach ($result->models as $m) {
            $byId[$m->providerId] = $m;
        }

        self::assertSame('Qwen3 32B', $byId['Qwen/Qwen3-32B']->name);
        self::assertSame(['chat'], $byId['Qwen/Qwen3-32B']->guessedTags);
        self::assertFalse($byId['Qwen/Qwen3-32B']->exists);

        self::assertSame(['vectorize'], $byId['BAAI/bge-m3']->guessedTags);
        self::assertTrue($byId['BAAI/bge-m3']->exists, 'already-in-catalog model is flagged');

        self::assertSame(['rerank'], $byId['BAAI/bge-reranker-v2-m3']->guessedTags);
        self::assertSame(['chat', 'pic2text'], $byId['mistralai/Pixtral-12B-2409']->guessedTags);
    }

    public function testUnreachableEndpointReturnsNotOkWithoutThrowing(): void
    {
        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('getEndpoint')->willReturn($this->endpointRow());
        $endpoints->method('listModelIds')->willReturn(['ok' => false, 'ids' => [], 'error' => 'timeout']);

        $result = $this->service($endpoints, $this->createMock(OllamaModelInventory::class), $this->createMock(ModelRepository::class))
            ->discover('openai_compatible:vllm-lab');

        self::assertFalse($result->ok);
        self::assertSame([], $result->models);
        self::assertSame('timeout', $result->error);
    }

    public function testUnknownEndpointThrows(): void
    {
        $endpoints = $this->createMock(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('getEndpoint')->willReturn(null);

        $this->expectException(UnknownImportSourceException::class);
        $this->service($endpoints, $this->createMock(OllamaModelInventory::class), $this->createMock(ModelRepository::class))
            ->discover('openai_compatible:ghost');
    }

    public function testUnknownSourcePrefixThrows(): void
    {
        $this->expectException(UnknownImportSourceException::class);
        $this->service(
            $this->createMock(OpenAiCompatibleEndpointRegistry::class),
            $this->createMock(OllamaModelInventory::class),
            $this->createMock(ModelRepository::class),
        )->discover('huggingface:whatever');
    }

    public function testOllamaListingCarriesSizeAndFamily(): void
    {
        $ollama = $this->createMock(OllamaModelInventory::class);
        $ollama->method('listPulled')->willReturn([
            'ok' => true,
            'models' => [
                ['name' => 'qwen3:32b', 'size' => 20016839168, 'family' => 'qwen3'],
                ['name' => 'bge-m3:latest', 'size' => 1157466051, 'family' => 'bert'],
                ['name' => 'llava:13b', 'size' => 8018393600, 'family' => 'llama'],
            ],
        ]);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findByServiceIndexedByProviderId')->willReturn([]);

        $result = $this->service($this->createMock(OpenAiCompatibleEndpointRegistry::class), $ollama, $models)
            ->discover('ollama');

        self::assertTrue($result->ok);
        self::assertCount(3, $result->models);
        self::assertSame('qwen3:32b', $result->models[0]->providerId);
        self::assertSame('qwen3:32b', $result->models[0]->name);
        self::assertSame(20016839168, $result->models[0]->sizeBytes);
        self::assertSame('qwen3', $result->models[0]->family);
        self::assertSame(['vectorize'], $result->models[1]->guessedTags);
        self::assertSame(['chat', 'pic2text'], $result->models[2]->guessedTags);
    }

    public function testUnreachableOllamaReturnsNotOk(): void
    {
        $ollama = $this->createMock(OllamaModelInventory::class);
        $ollama->method('listPulled')->willReturn(['ok' => false, 'models' => []]);

        $result = $this->service($this->createMock(OpenAiCompatibleEndpointRegistry::class), $ollama, $this->createMock(ModelRepository::class))
            ->discover('ollama');

        self::assertFalse($result->ok);
        self::assertSame([], $result->models);
    }

    private function service(
        OpenAiCompatibleEndpointRegistry $endpoints,
        OllamaModelInventory $ollama,
        ModelRepository $models,
    ): ModelDiscoveryService {
        return new ModelDiscoveryService($endpoints, $ollama, $models, new ModelTagGuesser());
    }

    /**
     * @return array{name: string, label: string, base_url: string, api_key: string, headers: array<string, string>, capabilities: string[]}
     */
    private function endpointRow(): array
    {
        return [
            'name' => 'vllm-lab',
            'label' => 'vLLM lab',
            'base_url' => 'http://vllm.test/v1',
            'api_key' => '',
            'headers' => [],
            'capabilities' => ['chat', 'vectorize'],
        ];
    }

    /**
     * @return list<string>
     */
    private function fixtureIds(string $file): array
    {
        $path = \dirname(__DIR__, 3).'/Fixtures/openai-compatible/'.$file;
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($data['data'] ?? [] as $row) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }
}
