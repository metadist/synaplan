<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Local AI (Ollama) — on-premise model runtime for chat, embeddings and
 * the "download a local model" admin flow.
 *
 * Only `OLLAMA_BASE_URL` is owned here; the `ollama` provider row of the
 * feature-status page keeps coming from the provider registry.
 */
final class LocalAiModule implements FeatureModuleInterface
{
    public const ID = 'local_ai';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $baseUrl,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.local_ai.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(envKeys: ['OLLAMA_BASE_URL'], providerKeys: ['ollama']);
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('OLLAMA_BASE_URL is unset');
        }

        $url = rtrim(trim($this->baseUrl), '/');
        $healthy = $this->probe->isReachable($url.'/api/tags');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Ollama is running' : 'Ollama is not reachable',
            details: ['url' => $url],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [
            'App\AI\Provider\OllamaProvider',
            'App\AI\Health\Probe\OllamaModelListProbe',
            'App\AI\Service\OllamaModelInventory',
            'App\Service\LocalAi\LocalAiDownloadStatusService',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/local-ai';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}
