<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncModelPricesCommand;
use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SyncModelPricesCommandTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private ModelRepository&MockObject $modelRepository;
    private ModelPriceHistoryRepository&MockObject $priceHistoryRepository;
    private EntityManagerInterface&MockObject $em;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->priceHistoryRepository = $this->createMock(ModelPriceHistoryRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $command = new SyncModelPricesCommand(
            $this->httpClient,
            $this->modelRepository,
            $this->priceHistoryRepository,
            $this->em,
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:sync-model-prices'));
    }

    public function testSuccessWithNoModels(): void
    {
        $this->mockLiteLLMResponse([]);
        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Price sync complete', $this->commandTester->getDisplay());
    }

    public function testFailsWhenLiteLLMFetchFails(): void
    {
        // @phpstan-ignore-next-line
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Network error'));

        $this->commandTester->execute([]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Failed to fetch LiteLLM prices', $this->commandTester->getDisplay());
    }

    public function testDryRunDoesNotFlush(): void
    {
        $model = $this->createModelMock('openai', 'gpt-4o', 3.0, 15.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->never())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('[DRY-RUN]', $this->commandTester->getDisplay());
    }

    public function testSkipsLocalProviders(): void
    {
        $ollamaModel = $this->createModelMock('ollama', 'llama3', 0.0, 0.0);

        $this->mockLiteLLMResponse([]);
        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$ollamaModel]);

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testNullPriceProtection(): void
    {
        $model = $this->createModelMock('openai', 'gpt-4o', 3.0, 15.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.0,
                'output_cost_per_token' => 0.0,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('null-price protected', $output);
        $this->assertStringContainsString('Null-price protected (1)', $output);
        $this->assertStringContainsString('openai/gpt-4o (ID 1)', $output);
    }

    public function testSkipsAdminPricesWithoutForce(): void
    {
        $model = $this->createModelMock('openai', 'gpt-4o', 3.0, 15.0);

        $adminPrice = $this->createMock(ModelPriceHistory::class);
        $adminPrice->method('getSource')->willReturn('admin');

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn($adminPrice);

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 skipped (admin)', $this->commandTester->getDisplay());
    }

    public function testUpdatesModelWithChangedPrices(): void
    {
        $model = $this->createModelMock('openai', 'gpt-4o', 3.0, 15.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
    }

    public function testProviderFilterSkipsOtherProviders(): void
    {
        $openaiModel = $this->createModelMock('openai', 'gpt-4o', 3.0, 15.0);
        $anthropicModel = $this->createModelMock('anthropic', 'claude-3-sonnet', 3.0, 15.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$openaiModel, $anthropicModel]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->commandTester->execute(['--provider' => 'openai']);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testMatchesPrefixedModelKey(): void
    {
        $model = $this->createModelMock('groq', 'llama-3.3-70b-versatile', 0.5, 0.8);

        $this->mockLiteLLMResponse([
            'groq/llama-3.3-70b-versatile' => [
                'input_cost_per_token' => 0.0000006,
                'output_cost_per_token' => 0.0000008,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
    }

    public function testMatchesGeminiPrefixedModelKey(): void
    {
        $model = $this->createModelMock('google', 'gemini-1.5-pro', 1.0, 2.0);

        $this->mockLiteLLMResponse([
            'gemini/gemini-1.5-pro' => [
                'input_cost_per_token' => 0.0000015,
                'output_cost_per_token' => 0.000003,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testSkipsWhenPriceUnchanged(): void
    {
        // Price in DB is already 5.0 per1M (= 0.000005 per token)
        $model = $this->createModelMock('openai', 'gpt-4o', 5.0, 20.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('0 updated', $output);
        $this->assertStringContainsString('1 unchanged', $output);
    }

    public function testMatchesCaseInsensitiveServicePrefix(): void
    {
        $model = $this->createModelMock('Groq', 'llama-3.3-70b-versatile', 0.5, 0.8);

        $this->mockLiteLLMResponse([
            'groq/llama-3.3-70b-versatile' => [
                'input_cost_per_token' => 0.0000006,
                'output_cost_per_token' => 0.0000008,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
    }

    public function testMatchesGeminiPrefixWithCapitalizedService(): void
    {
        $model = $this->createModelMock('Google', 'gemini-2.5-pro', 1.0, 2.0);

        $this->mockLiteLLMResponse([
            'gemini/gemini-2.5-pro' => [
                'input_cost_per_token' => 0.0000015,
                'output_cost_per_token' => 0.000003,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testMatchesNonChatModeModels(): void
    {
        $model = $this->createModelMock('openai', 'text-embedding-3-large', 0.01, 0.0);

        $this->mockLiteLLMResponse([
            'text-embedding-3-large' => [
                'input_cost_per_token' => 0.00000013,
                'output_cost_per_token' => 0.0,
                'mode' => 'embedding',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
    }

    public function testProviderFilterCaseInsensitive(): void
    {
        $model = $this->createModelMock('OpenAI', 'gpt-4o', 3.0, 15.0);

        $this->mockLiteLLMResponse([
            'gpt-4o' => [
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000020,
                'mode' => 'chat',
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute(['--provider' => 'openai']);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
    }

    public function testSyncsTtsPerCharacterPricing(): void
    {
        $model = $this->createModelMock('openai', 'tts-1', 0.0, 0.0);

        $this->mockLiteLLMResponse([
            'tts-1' => [
                'mode' => 'audio_speech',
                'input_cost_per_character' => 0.000015,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
        $this->assertStringContainsString('perChar', $this->commandTester->getDisplay());
    }

    public function testSyncsWhisperPerSecondPricing(): void
    {
        $model = $this->createModelMock('openai', 'whisper-1', 0.0, 0.0);

        $this->mockLiteLLMResponse([
            'whisper-1' => [
                'mode' => 'audio_transcription',
                'input_cost_per_second' => 0.0001,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
        $this->assertStringContainsString('perSec', $this->commandTester->getDisplay());
    }

    public function testSyncsImagePerImagePricing(): void
    {
        $model = $this->createModelMock('openai', 'dall-e-3', 0.0, 0.0);

        $this->mockLiteLLMResponse([
            'dall-e-3' => [
                'mode' => 'image_generation',
                'output_cost_per_image' => 0.04,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('1 updated', $this->commandTester->getDisplay());
        $this->assertStringContainsString('perImage', $this->commandTester->getDisplay());
    }

    public function testTokenBasedImageGenFallsBackToTokenPricing(): void
    {
        $model = $this->createModelMock('openai', 'gpt-image-1', 0.0, 0.0);

        $this->mockLiteLLMResponse([
            'gpt-image-1' => [
                'mode' => 'image_generation',
                'input_cost_per_token' => 0.000010,
                'output_cost_per_token' => 0.000040,
                'output_cost_per_image' => 0.04,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->once())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('per1M', $this->commandTester->getDisplay());
    }

    private function mockLiteLLMResponse(array $data): void
    {
        $response = $this->createMock(ResponseInterface::class);
        // @phpstan-ignore-next-line
        $response->method('toArray')->willReturn($data);
        // @phpstan-ignore-next-line
        $this->httpClient->method('request')->willReturn($response);
    }

    private function createModelMock(string $service, string $providerId, float $priceIn, float $priceOut, int $id = 1): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn('per1M');
        $model->method('getOutUnit')->willReturn('per1M');
        $model->method('getJson')->willReturn([]);

        return $model;
    }
}
