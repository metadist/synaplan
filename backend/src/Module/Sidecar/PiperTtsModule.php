<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Synaplan TTS (Piper) — local text-to-speech sidecar.
 *
 * The provider itself receives a defaulted URL (`synaplan_tts_url_default`), so
 * `PiperProvider::isAvailable()` is always true; the module reads the raw
 * `SYNAPLAN_TTS_URL` so "configured" means "an operator pointed at a service".
 */
final class PiperTtsModule implements FeatureModuleInterface
{
    public const ID = 'piper_tts';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $ttsUrl,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.piper_tts.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(envKeys: ['SYNAPLAN_TTS_URL'], providerKeys: ['piper']);
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->ttsUrl);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('SYNAPLAN_TTS_URL is unset');
        }

        $url = rtrim(trim($this->ttsUrl), '/');
        $healthy = $this->probe->isReachable($url.'/health');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Synaplan TTS is running' : 'Synaplan TTS is not reachable',
            details: ['url' => $url],
        );
    }

    public function capabilityIds(): array
    {
        return ['text_to_speech'];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [
            'App\AI\Provider\PiperProvider',
            'App\Controller\TtsController',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/piper-tts';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
