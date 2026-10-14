<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\Model\ModelAvailabilityCheck;
use PHPUnit\Framework\TestCase;

final class ModelAvailabilityCheckTest extends TestCase
{
    public function testAnUnknownModelIdIsNotFound(): void
    {
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn(null);

        $check = new ModelAvailabilityCheck($models, $this->createStub(ProviderRegistry::class), $this->createStub(ChatReadinessService::class));

        self::assertNull($check->check(404));
    }

    public function testARunningOllamaWithoutTheModelSaysSoAndHowToPullIt(): void
    {
        $ollama = $this->createStub(ChatProviderInterface::class);
        $ollama->method('getStatus')->willReturn(['healthy' => true]);
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($ollama);
        $readiness = $this->createStub(ChatReadinessService::class);
        $readiness->method('isOllamaModelPulled')->willReturn(false);

        $result = $this->check($this->model('ollama', 'llama3.2'), $registry, $readiness);

        self::assertFalse($result['available']);
        self::assertSame('local', $result['provider_type']);
        self::assertSame('Ollama is running but the model "llama3.2" is not downloaded yet', $result['message'] ?? null);
        self::assertSame('docker compose exec ollama ollama pull llama3.2', $result['install_command'] ?? null);
    }

    public function testAPulledOllamaModelIsAvailable(): void
    {
        $ollama = $this->createStub(ChatProviderInterface::class);
        $ollama->method('getStatus')->willReturn(['healthy' => true]);
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($ollama);
        $readiness = $this->createStub(ChatReadinessService::class);
        $readiness->method('isOllamaModelPulled')->willReturn(true);

        $result = $this->check($this->model('ollama', 'llama3.2'), $registry, $readiness);

        self::assertTrue($result['available']);
        self::assertArrayNotHasKey('message', $result);
    }

    public function testAHostedProviderWithoutItsKeyNamesTheMissingSetting(): void
    {
        $provider = $this->createStub(ProviderMetadataInterface::class);
        $provider->method('getName')->willReturn('openai');
        $provider->method('isAvailable')->willReturn(false);
        $provider->method('getRequiredEnvVars')->willReturn(['OPENAI_API_KEY_FOR_THIS_TEST' => ['required' => true]]);
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn(['openai' => $provider]);

        $result = $this->check($this->model('OpenAI', 'gpt-test'), $registry, $this->createStub(ChatReadinessService::class));

        self::assertFalse($result['available']);
        self::assertSame('external', $result['provider_type']);
        self::assertSame('openai', $result['service']);
        self::assertSame('OPENAI_API_KEY_FOR_THIS_TEST', $result['env_var'] ?? null);
        self::assertSame('Configuration not complete for openai', $result['message'] ?? null);
    }

    public function testAProviderTheRegistryDoesNotKnowIsAssumedReady(): void
    {
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn([]);

        $result = $this->check($this->model('custom', 'house-model'), $registry, $this->createStub(ChatReadinessService::class));

        self::assertTrue($result['available']);
        self::assertSame('unknown', $result['provider_type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(Model $model, ProviderRegistry $registry, ChatReadinessService $readiness): array
    {
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn($model);

        $result = (new ModelAvailabilityCheck($models, $registry, $readiness))->check(1);
        self::assertNotNull($result);

        return $result;
    }

    private function model(string $service, string $providerId): Model
    {
        return (new Model())->setService($service)->setProviderId($providerId);
    }
}
