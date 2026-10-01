<?php

declare(strict_types=1);

namespace App\Module\Federation;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Federation\FederationUrlGuard;
use App\Service\Security\SsrfGuard;

/**
 * Partners — an admin opens this Synaplan to other companies.
 *
 * There is no on/off env flag. The server is reachable when APP_URL is public
 * https (or the dev allow-local flag is set). Opening to partners is a choice
 * the admin makes in the product, stored with the key pair, default closed.
 */
final class FederationModule implements FeatureModuleInterface
{
    public const ID = 'federation';

    public function __construct(
        private readonly string $appUrl,
        private readonly bool $allowLocal,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.federation.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('FEDERATION_ALLOW_LOCAL');
    }

    public function isConfigured(): bool
    {
        return $this->guard()->isReachableAppUrl($this->appUrl);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('Missing: a public https APP_URL that other companies can reach', [
                'missing' => ['APP_URL'],
            ]);
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: 'Partners can be opened by an admin',
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [
            'federation_well_known',
            'api_federation_invite_preview',
            'api_federation_connect',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Federation\FederationSigner',
            'App\Service\Federation\FederationUrlGuard',
            'App\Service\Federation\FederationIdentityStore',
            'App\Service\Federation\FederationPeerClient',
            'App\Service\Federation\FederationLinkService',
            'App\Controller\FederationWellKnownController',
            'App\Controller\FederationInviteController',
            'App\Controller\FederationConnectController',
            'App\Controller\FederationAdminController',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/federation';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }

    private function guard(): FederationUrlGuard
    {
        return new FederationUrlGuard(new SsrfGuard(), $this->allowLocal);
    }
}
