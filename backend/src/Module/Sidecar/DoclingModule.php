<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Docling — layout-aware document conversion sidecar (optional, off by default).
 *
 * `isConfigured()` mirrors `DoclingClient::isEnabled()`; `status()` mirrors the
 * historic `docling` block of the feature-status page (`GET /health`).
 */
final class DoclingModule implements FeatureModuleInterface
{
    public const ID = 'docling';

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
        return 'modules.docling.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('DOCLING_BASE_URL', 'DOCLING_TIMEOUT_MS', 'DOCLING_MAX_BYTES');
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);

        return '' !== $url && 'disabled' !== strtolower($url);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('DOCLING_BASE_URL is unset or disabled');
        }

        $url = trim($this->baseUrl);
        $healthy = $this->probe->isReachable(rtrim($url, '/').'/health');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Docling is running' : 'Docling is not reachable',
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
            'App\Plug\Extraction\Docling\DoclingClient',
            'App\Plug\Extraction\Adapter\DoclingExtractor',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/docling';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
