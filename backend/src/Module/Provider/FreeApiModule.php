<?php

declare(strict_types=1);

namespace App\Module\Provider;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiAutoLeave;
use App\Service\FreeApi\FreeApiCallContext;
use App\Service\FreeApi\FreeApiClient;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiModelBinder;
use App\Service\FreeApi\FreeApiRequestHeaders;
use App\Service\FreeApi\FreeApiState;

/**
 * Synaplan Demo: a free, limited AI key from synaplan.com so a fresh install
 * can chat before it has a provider of its own.
 *
 * Off unless the admin row `FREEAPI.ENABLED` (pin: FEATURE_FREEAPI_ENABLED)
 * is on and FREEAPI_BASE_URL names a usable server. The status reads local
 * state only; nothing talks to the Demo server until an admin starts it.
 */
final class FreeApiModule implements FeatureModuleInterface
{
    public const ID = 'freeapi';

    public function __construct(
        private readonly FreeApiConfig $config,
        private readonly FreeApiState $state,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.freeapi.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['FREEAPI_BASE_URL'],
            bconfigKeys: [FreeApiConfig::GROUP.'.'.FreeApiConfig::ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function status(): ModuleStatus
    {
        if (!$this->config->isEnabled()) {
            return ModuleStatus::absent('The Synaplan Demo is turned off', ['missing' => [FreeApiConfig::GROUP.'.'.FreeApiConfig::ENABLED]]);
        }
        if (null === $this->config->baseUrl()) {
            return ModuleStatus::absent('Missing: a FREEAPI_BASE_URL this environment may use', ['missing' => ['FREEAPI_BASE_URL']]);
        }

        $active = $this->state->isActive();

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: $active ? 'The Synaplan Demo answers chat on this server' : 'The Synaplan Demo can be started',
            details: ['active' => $active, 'expires_at' => $active ? $this->state->expiresAt() : null],
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
            FreeApiConfig::class,
            FreeApiState::class,
            FreeApiClient::class,
            FreeApiActivator::class,
            FreeApiAutoLeave::class,
            FreeApiModelBinder::class,
            FreeApiRequestHeaders::class,
            FreeApiCallContext::class,
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/freeapi';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}
