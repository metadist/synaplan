<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Apache Tika — text extraction sidecar for office documents and PDFs.
 *
 * `isConfigured()` mirrors `TikaClient::isEnabled()`; `status()` mirrors the
 * historic `tika` block of the feature-status page (reachability of `/tika`,
 * version from `/version`).
 */
final class TikaModule implements FeatureModuleInterface
{
    public const ID = 'tika';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $baseUrl,
        private readonly ?string $httpUser = null,
        private readonly ?string $httpPass = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.tika.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'TIKA_BASE_URL',
            'TIKA_HTTP_USER',
            'TIKA_HTTP_PASS',
            'TIKA_TIMEOUT_MS',
            'TIKA_RETRIES',
            'TIKA_RETRY_BACKOFF_MS',
            'TIKA_MIN_LENGTH',
            'TIKA_MIN_ENTROPY',
        );
    }

    public function isConfigured(): bool
    {
        return '' !== $this->baseUrl && 'disabled' !== $this->baseUrl;
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('TIKA_BASE_URL is unset or disabled');
        }

        $url = rtrim($this->baseUrl, '/');
        $healthy = $this->probe->isReachable($url.'/tika', $this->httpUser, $this->httpPass);
        $version = $healthy ? $this->probe->fetchText($url.'/version', $this->httpUser, $this->httpPass) : null;

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Tika is running' : 'Tika is not reachable',
            details: [
                'url' => $this->baseUrl,
                'version' => null === $version ? null : trim($version),
            ],
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
            'App\Service\File\TikaClient',
            'App\Plug\Extraction\Adapter\TikaExtractor',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/tika';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
