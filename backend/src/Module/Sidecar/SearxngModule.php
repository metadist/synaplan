<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * SearXNG — self-hosted meta search used as an optional web-search plug.
 *
 * `isConfigured()` mirrors `SearxngClient::isConfigured()`. The plug's own
 * `probe()` performs a real search query; the module status only checks that
 * the instance answers, so the status page stays cheap.
 */
final class SearxngModule implements FeatureModuleInterface
{
    public const ID = 'searxng';

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
        return 'modules.searxng.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(envKeys: ['SEARXNG_BASE_URL'], plugKeys: ['searxng']);
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);

        return '' !== $url && 'disabled' !== strtolower($url);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('SEARXNG_BASE_URL is unset or disabled');
        }

        $url = rtrim(trim($this->baseUrl), '/');
        $healthy = $this->probe->isReachable($url.'/healthz');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'SearXNG is running' : 'SearXNG is not reachable',
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
            'App\Plug\WebSearch\Client\SearxngClient',
            'App\Plug\WebSearch\Adapter\SearxngAdapter',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/searxng';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
