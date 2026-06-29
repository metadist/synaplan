<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BillingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BillingService: the SaaS-vs-open-source toggle and the
 * per-channel "where do I manage this subscription" hint (Epic 5.1).
 */
final class BillingServiceTest extends TestCase
{
    public function testIsDisabledWithPlaceholderSecretKey(): void
    {
        $service = new BillingService('sk_your_key_here', 'price_real_123');

        $this->assertFalse($service->isEnabled());
    }

    public function testIsDisabledWithPlaceholderPriceId(): void
    {
        $service = new BillingService('sk_live_realsecret', 'price_pro');

        $this->assertFalse($service->isEnabled());
    }

    public function testIsEnabledWithRealConfig(): void
    {
        $service = new BillingService('sk_live_realsecret', 'price_1RealStripeId');

        $this->assertTrue($service->isEnabled());
    }

    public function testManageUrlForApple(): void
    {
        $service = new BillingService('sk_live_x', 'price_1x');

        $this->assertSame(
            'https://apps.apple.com/account/subscriptions',
            $service->getManageUrl(BillingService::SOURCE_APPLE),
        );
    }

    public function testManageUrlForGoogle(): void
    {
        $service = new BillingService('sk_live_x', 'price_1x');

        $this->assertSame(
            'https://play.google.com/store/account/subscriptions',
            $service->getManageUrl(BillingService::SOURCE_GOOGLE),
        );
    }

    public function testManageUrlIsNullForStripeAndUnknown(): void
    {
        $service = new BillingService('sk_live_x', 'price_1x');

        // Stripe is managed via the on-demand billing portal, not a static URL.
        $this->assertNull($service->getManageUrl(BillingService::SOURCE_STRIPE));
        $this->assertNull($service->getManageUrl(null));
        $this->assertNull($service->getManageUrl('paypal'));
    }
}
