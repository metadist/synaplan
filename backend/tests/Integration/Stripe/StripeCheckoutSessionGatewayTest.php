<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe;

use App\Service\Billing\PayFirst\StripeCheckoutSessionGateway;
use App\Tests\Integration\Stripe\Mock\StripeMockHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * How a Stripe Checkout session reads to the pay-first flow: who paid, for
 * what, and whether the purchase is settled — including a first period that
 * a 100% promotion code made free.
 */
final class StripeCheckoutSessionGatewayTest extends TestCase
{
    private StripeMockHttpClient $stripe;

    protected function setUp(): void
    {
        $this->stripe = new StripeMockHttpClient();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    public function testAFullyDiscountedFirstPeriodIsSettled(): void
    {
        $this->stripe->expect('GET', '/v1/checkout/sessions/cs_free', $this->session('cs_free', 'no_payment_required'));

        $checkout = $this->gateway()->retrieve('cs_free');

        self::assertNotNull($checkout);
        self::assertTrue($checkout->isSettled());
        self::assertSame('PRO', $checkout->plan);
        self::assertSame('ada@example.test', $checkout->email);
    }

    public function testAnUnpaidSessionIsNotSettled(): void
    {
        $this->stripe->expect('GET', '/v1/checkout/sessions/cs_unpaid', $this->session('cs_unpaid', 'unpaid'));

        self::assertFalse($this->gateway()->retrieve('cs_unpaid')?->isSettled());
    }

    public function testASessionFromAnotherFlowIsNotPayFirst(): void
    {
        $session = $this->session('cs_other', 'paid');
        $session['metadata'] = ['type' => 'topup'];
        $this->stripe->expect('GET', '/v1/checkout/sessions/cs_other', $session);

        self::assertNull($this->gateway()->retrieve('cs_other'));
    }

    public function testAnUnknownSessionIsNull(): void
    {
        $this->stripe->expect('GET', '/v1/checkout/sessions/cs_nobody', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such checkout.session']], 404);

        self::assertNull($this->gateway()->retrieve('cs_nobody'));
    }

    private function gateway(): StripeCheckoutSessionGateway
    {
        return new StripeCheckoutSessionGateway('sk_test_gateway', new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    private function session(string $id, string $paymentStatus): array
    {
        return [
            'id' => $id,
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => $paymentStatus,
            'customer' => 'cus_'.$id,
            'subscription' => 'sub_'.$id,
            'customer_email' => null,
            'customer_details' => ['email' => 'Ada@Example.test'],
            'metadata' => ['flow' => 'pay_first', 'plan' => 'pro', 'start_nonce_hash' => str_repeat('a', 64)],
        ];
    }
}
