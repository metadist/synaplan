<?php

declare(strict_types=1);

namespace App\Module\Provider;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;

/**
 * TheHive — media generation provider keyed by a single env variable.
 *
 * `isConfigured()` mirrors `TheHiveProvider::isAvailable()`.
 */
final class TheHiveModule implements FeatureModuleInterface
{
    public const ID = 'thehive';

    public function __construct(
        private readonly string $apiKey,
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
        return new ConfiguredBy(envKeys: ['THEHIVE_API_KEY'], providerKeys: ['thehive']);
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('THEHIVE_API_KEY is unset');
        }

        return new ModuleStatus(configured: true, healthy: true, message: 'TheHive API key present');
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
