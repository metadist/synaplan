<?php

declare(strict_types=1);

namespace App\Module\Commerce;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Iap\AppleReceiptVerifierInterface;
use App\Service\Iap\GooglePlayVerifierInterface;
use App\Service\IapPricingService;

/**
 * Mobile in-app purchases — store product mapping plus Apple / Google receipt
 * verification.
 *
 * `isConfigured()` is `IapPricingService::isConfigured()` (at least one tier
 * mapped to a store product), the same test `subscription_plans` reports as
 * `iapConfigured`. Verifier readiness (certificates, service account) is
 * reported in `status()` only, because both verifiers touch the filesystem.
 *
 * The store notification webhooks (`iap_apple_notifications`,
 * `iap_google_notifications`) are never listed: they must keep answering 503
 * so the stores retry rather than drop the event.
 */
final class MobileIapModule implements FeatureModuleInterface
{
    public const ID = 'mobile_iap';

    public function __construct(
        private readonly IapPricingService $pricing,
        private readonly AppleReceiptVerifierInterface $apple,
        private readonly GooglePlayVerifierInterface $google,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.mobile_iap.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'IAP_PRODUCT_PRO',
            'IAP_PRODUCT_TEAM',
            'IAP_PRODUCT_BUSINESS',
            'IAP_PRICE_MARKUP_PERCENT',
            'IAP_STORE_PRICE_PRO',
            'IAP_STORE_PRICE_TEAM',
            'IAP_STORE_PRICE_BUSINESS',
            'IAP_APPLE_BUNDLE_ID',
            'IAP_APPLE_APP_APPLE_ID',
            'IAP_APPLE_ENVIRONMENT',
            'IAP_APPLE_ROOT_CERTS_DIR',
            'IAP_GOOGLE_PACKAGE_NAME',
            'IAP_GOOGLE_SERVICE_ACCOUNT_JSON',
        );
    }

    public function isConfigured(): bool
    {
        return $this->pricing->isConfigured();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('No IAP_PRODUCT_* tier is mapped to a store product');
        }

        $apple = $this->apple->isConfigured();
        $google = $this->google->isConfigured();
        $healthy = $apple || $google;

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy
                ? 'IAP products mapped; receipt verification ready'
                : 'IAP products mapped but no store verifier is configured',
            details: [
                'products' => array_keys($this->pricing->productCatalogue()),
                'apple_verifier' => $apple,
                'google_verifier' => $google,
            ],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return ['iap_verify'];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\IapPricingService',
            'App\Service\Iap\AppleStoreKitVerifier',
            'App\Service\Iap\GooglePlayVerifier',
            'App\Service\MobilePurchaseService',
            'App\Controller\MobilePurchaseController',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/mobile-iap';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
