<?php

declare(strict_types=1);

namespace App\Module\Provider;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;

/**
 * Higgsfield — image/video generation provider with platform-level or
 * per-user credentials.
 *
 * "Configured" is the instance pair from Models & keys (saved in the admin UI
 * or bootstrapped from `HIGGSFIELD_API_KEY` + `_SECRET`); users connecting
 * their own account do not flip the module, they only unlock it for
 * themselves. No network probe: the credential routes already test keys.
 */
final class HiggsfieldModule implements FeatureModuleInterface
{
    public const ID = 'higgsfield';

    public function __construct(
        private readonly HiggsfieldCredentialResolver $credentials,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.higgsfield.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['HIGGSFIELD_API_KEY', 'HIGGSFIELD_API_SECRET'],
            bconfigKeys: ['PROVIDER_KEYS.higgsfield'],
            providerKeys: ['higgsfield'],
        );
    }

    public function isConfigured(): bool
    {
        return $this->credentials->hasPlatformCredentials();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('No Higgsfield key + secret pair — add it under AI infrastructure › Models & keys or set HIGGSFIELD_API_KEY and HIGGSFIELD_API_SECRET');
        }

        return new ModuleStatus(configured: true, healthy: true, message: 'Higgsfield platform credentials present');
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
            'App\AI\Provider\HiggsfieldProvider',
            'App\AI\Credential\HiggsfieldCredentialResolver',
            'App\Controller\AI\HiggsfieldCredentialController',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/higgsfield';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}
