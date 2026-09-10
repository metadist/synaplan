<?php

declare(strict_types=1);

namespace App\Module\Commerce;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\BillingService;

/**
 * Stripe billing — web checkout, top-ups, billing portal and the Stripe
 * webhook.
 *
 * `isConfigured()` is `BillingService::isEnabled()` (real secret key + real
 * PRO price id), the same test `subscription_plans` reports as
 * `stripeConfigured`. `status()` names missing keys, never their values.
 *
 * Never listed as routes: `subscription_plans` / `subscription_status` /
 * `subscription_budget` (shared with IAP users) and `stripe_webhook` (Stripe
 * expects its own status codes). The frontend billing files are
 * store-required under the mobile policy regardless of this class.
 */
final class StripeBillingModule implements FeatureModuleInterface
{
    public const ID = 'stripe_billing';

    private const REQUIRED_ENV_KEYS = ['STRIPE_SECRET_KEY', 'STRIPE_PRICE_PRO', 'STRIPE_WEBHOOK_SECRET'];

    public function __construct(
        private readonly BillingService $billing,
        private readonly string $stripeSecretKey,
        private readonly string $stripePricePro,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.stripe_billing.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'STRIPE_SECRET_KEY',
            'STRIPE_WEBHOOK_SECRET',
            'STRIPE_PRICE_PRO',
            'STRIPE_PRICE_TEAM',
            'STRIPE_PRICE_BUSINESS',
            'STRIPE_PAYMENT_METHODS',
            'STRIPE_AUTOMATIC_TAX',
        );
    }

    public function isConfigured(): bool
    {
        return $this->billing->isEnabled();
    }

    public function status(): ModuleStatus
    {
        $missing = $this->missingEnvKeys();

        if (!$this->isConfigured()) {
            $message = [] === $missing
                ? 'Stripe keys are placeholders'
                : 'Missing: '.implode(', ', $missing);

            return new ModuleStatus(configured: false, healthy: false, message: $message, details: ['missing' => $missing]);
        }

        $healthy = [] === $missing;

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Stripe billing configured' : 'Stripe configured but webhook secret missing',
            details: ['missing' => $missing],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [
            'subscription_checkout',
            'subscription_topup',
            'subscription_portal',
            'subscription_cancel',
            'subscription_sync',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\BillingService',
            'App\Controller\SubscriptionController',
            'App\Controller\StripeWebhookController',
            'App\Service\PremiumFeatureGate',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/stripe-billing';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }

    /**
     * @return list<string>
     */
    private function missingEnvKeys(): array
    {
        $values = [
            'STRIPE_SECRET_KEY' => $this->stripeSecretKey,
            'STRIPE_PRICE_PRO' => $this->stripePricePro,
            'STRIPE_WEBHOOK_SECRET' => $this->stripeWebhookSecret,
        ];

        $missing = [];
        foreach (self::REQUIRED_ENV_KEYS as $key) {
            if ('' === trim($values[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
