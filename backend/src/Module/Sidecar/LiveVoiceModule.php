<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\AI\Credential\ProviderKeyStore;
use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Repository\ModelRepository;
use App\Service\Voice\VoiceLiveCompatibleEndpoint;

/**
 * Full-duplex live voice. Absent until the gateway URL and secret are set
 * and at least one active speech-to-speech model has a provider key.
 * The browser never opens the provider socket; the sidecar does.
 */
final class LiveVoiceModule implements FeatureModuleInterface
{
    public const ID = 'live_voice';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $gatewayUrl,
        private readonly string $gatewaySecret,
        private readonly ModelRepository $models,
        private readonly ProviderKeyStore $keys,
        private readonly ?VoiceLiveCompatibleEndpoint $compatible = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.live_voice.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'LIVE_VOICE_GATEWAY_URL',
            'LIVE_VOICE_GATEWAY_SECRET',
            'LIVE_VOICE_PUBLIC_WS_PATH',
        );
    }

    public function isConfigured(): bool
    {
        if (!$this->gatewayPointed()) {
            return false;
        }

        foreach ($this->models->findActiveByTag('voice2voice') as $model) {
            $provider = strtolower($model->getService());
            if (VoiceLiveCompatibleEndpoint::PROVIDER === $provider) {
                if ($this->compatible?->configured() ?? false) {
                    return true;
                }
                continue;
            }
            $status = $this->keys->getStatus($provider);
            if ($status['configured']) {
                return true;
            }
        }

        return false;
    }

    public function status(): ModuleStatus
    {
        if (!$this->gatewayPointed()) {
            return ModuleStatus::absent('LIVE_VOICE_GATEWAY_URL or LIVE_VOICE_GATEWAY_SECRET is unset');
        }

        if (!$this->isConfigured()) {
            return ModuleStatus::absent('No active live-voice model has a provider key');
        }

        $url = rtrim(trim($this->gatewayUrl), '/');
        $healthy = $this->probe->isReachable($url.'/health');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Live voice is running' : 'Live voice gateway is not reachable',
            details: ['url' => $url],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return ['api_voice_live_*'];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Voice\VoiceLiveSessionService',
            'App\Service\Voice\VoiceLiveUsageService',
            'App\Service\Voice\VoiceLiveDelegationService',
            'App\Service\Voice\VoiceTranscriptWriter',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/live-voice';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }

    private function gatewayPointed(): bool
    {
        $url = trim($this->gatewayUrl);

        return '' !== $url && 'disabled' !== $url && '' !== trim($this->gatewaySecret);
    }
}
