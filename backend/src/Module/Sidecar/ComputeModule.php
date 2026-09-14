<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Service\Compute\ComputeConfig;

/**
 * Secure compute sidecar — sandboxed file work for the assistant.
 *
 * `isConfigured()` is URL + token (the sidecar is pointed at). The product
 * flag `COMPUTE.ENABLED` is an extra switch: the capability stays absent
 * until both the sidecar and the flag are on.
 */
final class ComputeModule implements FeatureModuleInterface
{
    public const ID = 'compute';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly ComputeConfig $config,
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.compute.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['COMPUTE_URL', 'COMPUTE_TOKEN'],
            bconfigKeys: [ComputeConfig::CONFIG_GROUP.'.'.ComputeConfig::KEY_ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);
        $token = trim($this->token);

        return '' !== $url && 'disabled' !== $url && '' !== $token;
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('COMPUTE_URL or COMPUTE_TOKEN is unset');
        }

        $url = rtrim(trim($this->baseUrl), '/');
        $healthy = $this->probe->isReachable($url.'/v1/health');
        $flagOn = $this->config->isEnabled();
        $message = $healthy
            ? ($flagOn ? 'Secure compute is running' : 'Secure compute is running; COMPUTE.ENABLED is off')
            : 'Secure compute is not reachable';

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $message,
            details: ['url' => $url, 'enabled' => $flagOn],
        );
    }

    public function capabilityIds(): array
    {
        return ['code_execution'];
    }

    public function routeNames(): array
    {
        return ['api_compute_workspace*'];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Compute\ComputeClient',
            'App\Service\Compute\ComputeConfig',
            'App\Service\Compute\ComputeArtefactStore',
            'App\Service\Compute\ComputeWorkspaceService',
            'App\Service\Compute\ComputeEgressResolver',
            'App\Service\Compute\ComputeRequestBuilder',
            'App\Service\Multitask\Execution\Runner\CodeRunRunner',
            'App\AI\Messages\Tools\CodeExecutionTool',
            'App\AI\Messages\Tools\CodeExecutionInvoker',
            'App\Service\Compute\ComputeToolSource',
            'App\Service\Compute\ComputeRunGrant',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/compute';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}
