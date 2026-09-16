<?php

declare(strict_types=1);

namespace App\Module\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;

/**
 * TheHive — media generation provider keyed by a single instance key.
 *
 * `isConfigured()` mirrors `TheHiveProvider::isAvailable()`: the key comes
 * from Models & keys ({@see ProviderKeyStore}, `THEHIVE_API_KEY` bootstrap),
 * so a key saved at runtime counts without a restart.
 */
final class TheHiveModule implements FeatureModuleInterface
{
    public const ID = 'thehive';

    public function __construct(
        private readonly ProviderKeyStore $keyStore,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.thehive.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['THEHIVE_API_KEY'],
            bconfigKeys: ['PROVIDER_KEYS.thehive'],
            providerKeys: [self::ID],
        );
    }

    public function isConfigured(): bool
    {
        return $this->keyStore->getStatus(self::ID)['configured'];
    }

    public function status(): ModuleStatus
    {
        $status = $this->keyStore->getStatus(self::ID);
        if (!$status['configured']) {
            return ModuleStatus::absent('No TheHive API key — add one under AI infrastructure › Models & keys or set THEHIVE_API_KEY');
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: 'TheHive API key present',
            details: ['source' => $status['source']],
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
        return ['App\AI\Provider\TheHiveProvider'];
    }

    public function docsAnchor(): string
    {
        return 'modules/thehive';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
