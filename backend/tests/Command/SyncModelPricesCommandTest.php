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
        // Assert the stable label, not "1 skipped" — SymfonyStyle wraps the summary
        // box between the count and the label. No persist above already proves the
        // admin-skip branch was taken.
        $this->assertStringContainsString('skipped (admin)', $this->commandTester->getDisplay());
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

    public function testModeMismatchTtsIsReportedNotWritten(): void
    {
        // tts-1 is per_token in the catalog (no explicit mode) but LiteLLM says
        // audio_speech/per_character. The two numbers measure different things, so
        // this is a structural mode mismatch: reported for a human, never written.
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

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Pricing-mode mismatch', $output);
        $this->assertStringContainsString('litellm=per_character', $output);
    }

    public function testSameModeTranscriptionIsCheckedAndClean(): void
    {
        // whisper is authored per_second in the catalog (perhour unit, #1314) and
        // LiteLLM agrees on per_second. Same mode → the sync DOES check it. Here the
        // normalised prices match, so it is counted as unchanged (not skipped).
        // $0.111/hour ÷ 3600 = $0.0000308333/sec.
        $model = $this->createNonTokenModelMock(
            'openai',
            'whisper-1',
            priceIn: 0.111,
            inUnit: 'perhour',
            priceOut: 0.0,
            outUnit: '-',
            mode: 'per_second',
        );

        $this->mockLiteLLMResponse([
            'whisper-1' => [
                'mode' => 'audio_transcription',
                'input_cost_per_second' => 0.111 / 3600,
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
        $this->assertStringContainsString('1 unchanged', $output);
        $this->assertStringNotContainsString('Non-per-token price drift', $output);
    }

    public function testSameModeTranscriptionDriftIsDetectedNotWritten(): void
    {
        // Same as above but LiteLLM's per-second price has moved. The sync must
        // DETECT the drift (this is the whole point of #1318 — whisper/tts/veo/imagen
        // are no longer invisible), report it for a human, but never auto-write a
        // hand-authored media row.
        $model = $this->createNonTokenModelMock(
            'openai',
            'whisper-1',
            priceIn: 0.111,
            inUnit: 'perhour',
            priceOut: 0.0,
            outUnit: '-',
            mode: 'per_second',
        );

        $this->mockLiteLLMResponse([
            'whisper-1' => [
                'mode' => 'audio_transcription',
                'input_cost_per_second' => 0.0002, // way off the $0.0000308/sec catalog value
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('findAll')->willReturn([$model]);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findCurrentPrice')->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute(['--dry-run' => true, '--fail-on-drift' => true]);

        $this->assertSame(2, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Non-per-token price drift', $output);
        $this->assertStringContainsString('Price drift detected', $output);
    }

    public function testCatalogPerImageDriftDetectedButNotWritten(): void
    {
        // A per_image catalog model vs a per_image LiteLLM value: same mode, so the
        // sync compares and detects drift (0.042 vs 0.099), but still never writes
        // the hand-maintained tier row.
        $model = $this->createNonTokenModelMock(
            'openai',
            'gpt-image-1',
            priceIn: 0.0,
            inUnit: 'perImage',
            priceOut: 0.042,
            outUnit: 'perImage',
            mode: 'per_image',
            id: 29,
        );

        $this->mockLiteLLMResponse([
            'gpt-image-1' => [
                'mode' => 'image_generation',
                'output_cost_per_image' => 0.099,
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
        $this->assertStringContainsString('Non-per-token price drift', $output);
        $this->assertStringContainsString('gpt-image-1', $output);
    }

    public function testModeMismatchImageIsReportedNotDrift(): void
    {
        // dall-e-3 is per_token in the catalog, LiteLLM flat per_image → mode
        // mismatch (structural), reported but never counted as drift.
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

        $this->em->expects($this->never())->method('persist');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Pricing-mode mismatch', $output);
        $this->assertStringContainsString('litellm=per_image', $output);
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

    public function testFailOnDriftReturnsDriftExitCodeWhenPricesDiffer(): void
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

        $this->commandTester->execute(['--dry-run' => true, '--fail-on-drift' => true]);

        // Exit code 2 = drift detected (distinct from generic failure).
        $this->assertSame(2, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Price drift detected', $this->commandTester->getDisplay());
    }

    public function testFailOnDriftSucceedsWhenNoDrift(): void
    {
        // DB already at LiteLLM price → no drift.
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

        $this->commandTester->execute(['--dry-run' => true, '--fail-on-drift' => true]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testFailOnDriftIgnoresModeMismatch(): void
    {
        // A per_token catalog model that LiteLLM reports as flat per_image is a
        // structural mode mismatch — permanent, so it must NEVER fail the drift gate
        // (otherwise the weekly CI would be red forever).
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

        $this->commandTester->execute(['--dry-run' => true, '--fail-on-drift' => true]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
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

    private function createNonTokenModelMock(
        string $service,
        string $providerId,
        float $priceIn,
        string $inUnit,
        float $priceOut,
        string $outUnit,
        string $mode,
        int $id = 1,
    ): Model {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('getJson')->willReturn(['pricing_mode' => $mode]);

        return $model;
    }
}
