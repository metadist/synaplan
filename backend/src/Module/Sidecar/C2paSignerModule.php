<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Content-credentials signer. It runs inside the Synaplan container on
 * localhost. The key file is owned by uid 65532 and is not in the PHP environment.
 * Absent means generated media are stored with the XMP fallback and not signed.
 */
final class C2paSignerModule implements FeatureModuleInterface
{
    public const ID = 'c2pa_signer';

    public const ABSENT_MESSAGE = 'Generated media are not signed. On a linux amd64 server the mark runs inside Synaplan when it is switched on.';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $baseUrl,
        private readonly string $token = '',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.c2pa_signer.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('C2PA_SIGNER_URL', 'C2PA_SIGNER_TOKEN');
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
            return ModuleStatus::absent(self::ABSENT_MESSAGE);
        }

        $url = rtrim($this->baseUrl, '/');
        $healthy = $this->probe->isReachable($url.'/health');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy
                ? 'Generated images, audio and video are signed.'
                : 'The content-credentials signer is not reachable.',
            details: ['url' => $this->baseUrl],
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
            'App\Service\Media\C2paSignerClient',
            'App\Service\Media\ContentCredentialsMark',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/c2pa-signer';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
