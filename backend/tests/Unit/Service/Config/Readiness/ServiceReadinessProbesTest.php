<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config\Readiness;

use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\ModuleStatus;
use App\Module\ModuleRegistry;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Module\Sidecar\DoclingModule;
use App\Module\Sidecar\LocalAiModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Module\Sidecar\PiperTtsModule;
use App\Module\Sidecar\SearxngModule;
use App\Module\Sidecar\TikaModule;
use App\Service\Config\Readiness\ReadinessObservation;
use App\Service\Config\Readiness\ServiceReadinessProbes;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\Memory\UserMemoryService;
use App\Service\Stt\SpeechFailure;
use App\Service\Stt\WhisperService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ServiceReadinessProbesTest extends TestCase
{
    private string $downloadFile;

    protected function setUp(): void
    {
        $this->downloadFile = sys_get_temp_dir().'/readiness-probe-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->downloadFile);
    }

    /**
     * @param array<string, ModuleStatus> $statuses
     */
    private function probes(
        array $statuses = [],
        ?string $whisperReason = null,
        bool $qdrantReachable = true,
        string $qdrantUrl = 'http://qdrant:6333',
        bool $realtimeEnabled = true,
        bool $centrifugoReachable = true,
    ): ServiceReadinessProbes {
        $factories = [];
        foreach ([LocalAiModule::ID, PiperTtsModule::ID, TikaModule::ID, DoclingModule::ID, SearxngModule::ID, OfficeConvertModule::ID] as $id) {
            $status = $statuses[$id] ?? new ModuleStatus(configured: true, healthy: true, message: 'ok');
            $module = $this->createStub(FeatureModuleInterface::class);
            $module->method('status')->willReturn($status);
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }

        $qdrant = $this->createStub(QdrantClientInterface::class);
        $qdrant->method('refreshHealth')->willReturn($qdrantReachable);
        $memory = $this->createStub(UserMemoryService::class);
        $memory->method('getQdrantClient')->willReturn($qdrant);
        $whisper = $this->createStub(WhisperService::class);
        $whisper->method('unavailableReason')->willReturn($whisperReason);
        $whisper->method('isAvailable')->willReturn(null === $whisperReason);
        $probe = $this->createStub(SidecarHealthProbeInterface::class);
        $probe->method('isReachable')->willReturn($centrifugoReachable);

        return new ServiceReadinessProbes(
            new ModuleRegistry(new ServiceLocator($factories)),
            new LocalAiDownloadStatusService($this->downloadFile),
            $memory,
            $whisper,
            $probe,
            $qdrantUrl,
            $realtimeEnabled,
            'http://centrifugo:8000/api',
        );
    }

    private function writeDownload(string $status, ?int $percent, ?string $model): void
    {
        file_put_contents($this->downloadFile, json_encode([
            'status' => $status,
            'currentModel' => $model,
            'percent' => $percent,
            'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR));
    }

    public function testAHealthyStackIsReadyEverywhere(): void
    {
        $kinds = array_map(static fn (ReadinessObservation $o): string => $o->kind, $this->probes()->observeAll());

        self::assertSame(['ollama', 'qdrant', 'whisper', 'tts', 'tika', 'docling', 'searxng', 'collabora', 'centrifugo'], array_keys($kinds));
        self::assertSame([ReadinessObservation::READY], array_values(array_unique($kinds)));
    }

    public function testAnAbsentModuleIsOffAndAnUnreachableOneIsDown(): void
    {
        $observed = $this->probes([
            DoclingModule::ID => ModuleStatus::absent('unset'),
            TikaModule::ID => new ModuleStatus(configured: true, healthy: false, message: 'down'),
        ])->observeAll();

        self::assertSame(ReadinessObservation::OFF, $observed['docling']->kind);
        self::assertSame(ReadinessObservation::DOWN, $observed['tika']->kind);
    }

    public function testOllamaReportsItsModelPullWhileItAnswers(): void
    {
        $this->writeDownload(LocalAiDownloadStatusService::STATUS_DOWNLOADING, 42, 'gpt-oss:20b');

        $ollama = $this->probes()->observeAll()['ollama'];

        self::assertSame(ReadinessObservation::DOWNLOADING, $ollama->kind);
        self::assertSame(42, $ollama->percent);
        self::assertSame('gpt-oss:20b', $ollama->model);
    }

    public function testOllamaThatDoesNotAnswerYetIsDownEvenWithAPendingPull(): void
    {
        $this->writeDownload(LocalAiDownloadStatusService::STATUS_WAITING, null, null);

        $ollama = $this->probes([LocalAiModule::ID => new ModuleStatus(configured: true, healthy: false, message: 'down')])->observeAll()['ollama'];

        self::assertSame(ReadinessObservation::DOWN, $ollama->kind);
    }

    public function testAFailedPullIsBroken(): void
    {
        $this->writeDownload(LocalAiDownloadStatusService::STATUS_ERROR, null, 'gpt-oss:20b');

        self::assertSame(ReadinessObservation::BROKEN, $this->probes()->observeAll()['ollama']->kind);
    }

    public function testWhisperMapsItsReasons(): void
    {
        self::assertSame(ReadinessObservation::OFF, $this->probes(whisperReason: SpeechFailure::SPEECH_OFF)->observeAll()['whisper']->kind);
        self::assertSame(ReadinessObservation::DOWNLOADING, $this->probes(whisperReason: SpeechFailure::MODEL_MISSING)->observeAll()['whisper']->kind);
        self::assertSame(ReadinessObservation::BROKEN, $this->probes(whisperReason: SpeechFailure::BINARY_MISSING)->observeAll()['whisper']->kind);
    }

    public function testQdrantWithoutAUrlIsOffAndUnreachableIsDown(): void
    {
        self::assertSame(ReadinessObservation::OFF, $this->probes(qdrantUrl: '')->observeAll()['qdrant']->kind);
        self::assertSame(ReadinessObservation::DOWN, $this->probes(qdrantReachable: false)->observeAll()['qdrant']->kind);
    }

    public function testCentrifugoIsOffWithRealtimeDisabled(): void
    {
        self::assertSame(ReadinessObservation::OFF, $this->probes(realtimeEnabled: false)->observeAll()['centrifugo']->kind);
        self::assertSame(ReadinessObservation::DOWN, $this->probes(centrifugoReachable: false)->observeAll()['centrifugo']->kind);
    }
}
