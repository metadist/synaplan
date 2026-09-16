<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Commerce;

use App\Module\Channel\WhatsappModule;
use App\Module\Commerce\MobileIapModule;
use App\Module\Commerce\StripeBillingModule;
use App\Service\BillingService;
use App\Service\Iap\AppleReceiptVerifierInterface;
use App\Service\Iap\GooglePlayVerifierInterface;
use App\Service\IapPricingService;
use PHPUnit\Framework\TestCase;

final class CommerceAndChannelModulesTest extends TestCase
{
    public function testStripeAbsentNamesMissingKeysButNeverValues(): void
    {
        $module = $this->stripe(secret: '', pricePro: '', webhook: '');

        $this->assertFalse($module->isConfigured());
        $status = $module->status();
        $this->assertSame('absent', $status->state());
        $this->assertSame(['STRIPE_SECRET_KEY', 'STRIPE_PRICE_PRO', 'STRIPE_WEBHOOK_SECRET'], $status->details['missing']);
        $this->assertSame('Missing: STRIPE_SECRET_KEY, STRIPE_PRICE_PRO, STRIPE_WEBHOOK_SECRET', $status->message);
    }

    public function testStripePlaceholdersAreNotConfigured(): void
    {
        $module = $this->stripe(secret: 'sk_test_your_key_here', pricePro: 'price_xxx', webhook: 'whsec_x');

        $this->assertFalse($module->isConfigured(), 'mirrors BillingService::isEnabled()');
        $this->assertSame('Stripe keys are placeholders', $module->status()->message);
    }

    public function testStripeConfiguredButWebhookMissingIsNeedsSetup(): void
    {
        $module = $this->stripe(secret: 'sk_live_abc', pricePro: 'price_1Real', webhook: '');

        $this->assertTrue($module->isConfigured());
        $status = $module->status();
        $this->assertSame('needs_setup', $status->state());
        $this->assertSame(['STRIPE_WEBHOOK_SECRET'], $status->details['missing']);
        $this->assertStringNotContainsString('sk_live_abc', json_encode($status->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testStripeFullyConfiguredIsAvailable(): void
    {
        $module = $this->stripe(secret: 'sk_live_abc', pricePro: 'price_1Real', webhook: 'whsec_real');

        $this->assertSame('available', $module->status()->state());
        $this->assertSame([], $module->status()->details['missing']);
    }

    public function testStripeNeverGatesSharedOrWebhookRoutes(): void
    {
        $routes = $this->stripe('', '', '')->routeNames();

        foreach (['subscription_plans', 'subscription_status', 'subscription_budget', 'stripe_webhook'] as $never) {
            $this->assertNotContains($never, $routes);
        }
        $this->assertContains('subscription_checkout', $routes);
    }

    public function testMobileIapIsConfiguredByAMappedProduct(): void
    {
        $absent = new MobileIapModule(new IapPricingService(), $this->apple(true), $this->google(true));
        $this->assertFalse($absent->isConfigured());
        $this->assertSame('absent', $absent->status()->state());

        $verified = new MobileIapModule(new IapPricingService(iapProductPro: 'com.synaplan.pro'), $this->apple(true), $this->google(false));
        $this->assertTrue($verified->isConfigured());
        $status = $verified->status();
        $this->assertSame('available', $status->state());
        $this->assertSame(['PRO'], $status->details['products']);
        $this->assertTrue($status->details['apple_verifier']);
        $this->assertFalse($status->details['google_verifier']);

        $unverified = new MobileIapModule(new IapPricingService(iapProductTeam: 'com.synaplan.team'), $this->apple(false), $this->google(false));
        $this->assertTrue($unverified->isConfigured());
        $this->assertSame('needs_setup', $unverified->status()->state());
    }

    public function testMobileIapNeverGatesStoreNotificationWebhooks(): void
    {
        $routes = (new MobileIapModule(new IapPricingService(), $this->apple(false), $this->google(false)))->routeNames();

        $this->assertSame(['iap_verify'], $routes);
    }

    public function testWhatsappMirrorsWhatsAppServiceAvailability(): void
    {
        $this->assertFalse((new WhatsappModule(false, 'token', 'verify'))->isConfigured());
        $this->assertFalse((new WhatsappModule(true, '', 'verify'))->isConfigured());
        $this->assertTrue((new WhatsappModule(true, 'token', ''))->isConfigured(), 'the verify token is not part of isAvailable()');
        $this->assertTrue((new WhatsappModule(true, 'token', 'verify'))->isConfigured());
    }

    public function testWhatsappStatusNamesMissingKeys(): void
    {
        $absent = (new WhatsappModule(false, '', ''))->status();
        $this->assertSame('absent', $absent->state());
        $this->assertSame(['WHATSAPP_ENABLED', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_WEBHOOK_VERIFY_TOKEN'], $absent->details['missing']);

        $noVerify = (new WhatsappModule(true, 'EAAB-secret-token', ''))->status();
        $this->assertSame('needs_setup', $noVerify->state());
        $this->assertSame(['WHATSAPP_WEBHOOK_VERIFY_TOKEN'], $noVerify->details['missing']);
        $this->assertStringNotContainsString('EAAB-secret-token', json_encode($noVerify->toArray(), JSON_THROW_ON_ERROR));

        $this->assertSame('available', (new WhatsappModule(true, 'token', 'verify'))->status()->state());
    }

    public function testWhatsappNeverGatesTheMetaHandshakeOrTheStatusRoute(): void
    {
        $routes = (new WhatsappModule(true, 'token', 'verify'))->routeNames();

        $this->assertNotContains('api_webhooks_whatsapp_verify', $routes);
        $this->assertNotContains('api_phone_verify_status', $routes);
        $this->assertContains('api_webhooks_whatsapp', $routes);
        $this->assertContains('api_whatsapp_assistant_*', $routes);
    }

    private function stripe(string $secret, string $pricePro, string $webhook): StripeBillingModule
    {
        return new StripeBillingModule(new BillingService($secret, $pricePro), $secret, $pricePro, $webhook);
    }

    private function apple(bool $configured): AppleReceiptVerifierInterface
    {
        $verifier = $this->createStub(AppleReceiptVerifierInterface::class);
        $verifier->method('isConfigured')->willReturn($configured);

        return $verifier;
    }

    private function google(bool $configured): GooglePlayVerifierInterface
    {
        $verifier = $this->createStub(GooglePlayVerifierInterface::class);
        $verifier->method('isConfigured')->willReturn($configured);

        return $verifier;
    }
}
