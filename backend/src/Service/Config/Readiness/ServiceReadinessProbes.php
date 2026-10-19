<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

use App\Module\ModuleRegistry;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Module\Sidecar\DoclingModule;
use App\Module\Sidecar\LocalAiModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Module\Sidecar\PiperTtsModule;
use App\Module\Sidecar\SearxngModule;
use App\Module\Sidecar\TikaModule;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\Memory\UserMemoryService;
use App\Service\Stt\SpeechFailure;
use App\Service\Stt\WhisperService;

/**
 * One observation per catalog service, built on the module registry and the
 * probes that already exist. Every probe is bounded by the sidecar probe's
 * short timeout; nothing here waits on a service that is still starting.
 */
final readonly class ServiceReadinessProbes
{
    private const MODULE_SERVICES = [
        'tts' => PiperTtsModule::ID,
        'tika' => TikaModule::ID,
        'docling' => DoclingModule::ID,
        'searxng' => SearxngModule::ID,
        'collabora' => OfficeConvertModule::ID,
    ];

    public function __construct(
        private ModuleRegistry $modules,
        private LocalAiDownloadStatusService $localAiDownload,
        private UserMemoryService $memoryService,
        private WhisperService $whisperService,
        private SidecarHealthProbeInterface $probe,
        private string $qdrantUrl,
        private bool $realtimeEnabled,
        private string $realtimeApiUrl,
    ) {
    }

    /**
     * @return array<string, ReadinessObservation> keyed by catalog id, in catalog order
     */
    public function observeAll(): array
    {
        $observations = [];
        foreach (array_keys(ServiceReadinessCatalog::SERVICES) as $id) {
            $observations[$id] = $this->observe($id);
        }

        return $observations;
    }

    private function observe(string $id): ReadinessObservation
    {
        if (isset(self::MODULE_SERVICES[$id])) {
            $status = $this->modules->get(self::MODULE_SERVICES[$id])->status();

            return ReadinessObservation::fromHealth($status->configured, $status->healthy);
        }

        return match ($id) {
            'ollama' => $this->observeLocalAi(),
            'qdrant' => $this->observeQdrant(),
            'whisper' => $this->observeWhisper(),
            'centrifugo' => $this->observeCentrifugo(),
            default => throw new \LogicException(sprintf('No readiness probe for service "%s".', $id)),
        };
    }

    private function observeLocalAi(): ReadinessObservation
    {
        $status = $this->modules->get(LocalAiModule::ID)->status();
        if (!$status->configured) {
            return ReadinessObservation::off();
        }

        $download = $this->localAiDownload->getStatus();
        if (LocalAiDownloadStatusService::STATUS_ERROR === $download['status']) {
            return ReadinessObservation::broken();
        }
        if ($this->localAiDownload->isActivelyDownloading()) {
            return $status->healthy
                ? ReadinessObservation::downloading($download['percent'], $download['currentModel'])
                : ReadinessObservation::down();
        }

        return ReadinessObservation::fromHealth(true, $status->healthy);
    }

    private function observeQdrant(): ReadinessObservation
    {
        $url = trim($this->qdrantUrl);
        if ('' === $url || 'http://' === $url || 'https://' === $url) {
            return ReadinessObservation::off();
        }

        return $this->memoryService->isReachableNow() ? ReadinessObservation::ready() : ReadinessObservation::down();
    }

    private function observeWhisper(): ReadinessObservation
    {
        return match ($this->whisperService->unavailableReason()) {
            SpeechFailure::SPEECH_OFF => ReadinessObservation::off(),
            SpeechFailure::MODEL_MISSING => ReadinessObservation::downloading(),
            null => $this->whisperService->isAvailable() ? ReadinessObservation::ready() : ReadinessObservation::broken(),
            default => ReadinessObservation::broken(),
        };
    }

    private function observeCentrifugo(): ReadinessObservation
    {
        $baseUrl = (string) preg_replace('#/api/?$#', '', trim($this->realtimeApiUrl));
        if (!$this->realtimeEnabled || '' === $baseUrl) {
            return ReadinessObservation::off();
        }

        return $this->probe->isReachable($baseUrl.'/health') ? ReadinessObservation::ready() : ReadinessObservation::down();
    }
}
