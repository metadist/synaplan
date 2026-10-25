<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing\PayFirst;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\Auth\SignupAnomalyAlert;
use App\Service\Billing\BillingService;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\PayFirst\PayFirstException;
use App\Service\Billing\PlanAvailability;
use App\Service\Billing\StripeEntitlement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Pay first (PF1): a checkout starts with an email address and a plan, binds
 * the starting browser through a nonce hash, and creates nothing up front.
 */
final class PayFirstCheckoutTest extends TestCase
{
    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new RecordingGateway();
    }

    public function testTheSessionCarriesTheEmailPlanAndNonceHashButNoCustomer(): void
    {
        $start = $this->checkout()->start('  ada@example.com ', 'pro', '203.0.113.1');

        self::assertSame('https://checkout.stripe.test/s/1', $start->checkoutUrl);
        self::assertCount(1, $this->gateway->created);
        $params = $this->gateway->created[0];
        self::assertArrayNotHasKey('customer', $params, 'no Stripe customer before the payment');
        self::assertArrayNotHasKey('client_reference_id', $params, 'no account before the payment');
        self::assertSame('ada@example.com', $params['customer_email']);
        self::assertSame('subscription', $params['mode']);
        self::assertSame([['price' => 'price_pro', 'quantity' => 1]], $params['line_items']);
        self::assertSame(['card', 'link', 'klarna'], $params['allowed_payment_method_types']);
        self::assertSame('pay_first', $params['metadata']['flow']);
        self::assertSame('PRO', $params['metadata']['plan']);
        self::assertSame(hash('sha256', $start->nonce), $params['metadata']['start_nonce_hash']);
        self::assertGreaterThanOrEqual(40, strlen($start->nonce));
        self::assertSame('https://app.example/welcome?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        self::assertSame('https://app.example/start?plan=PRO&cancelled=1', $params['cancel_url']);
    }

    public function testEachStartGetsItsOwnNonce(): void
    {
        $checkout = $this->checkout();

        $first = $checkout->start('ada@example.com', 'PRO', '203.0.113.1')->nonce;
        $second = $checkout->start('ada@example.com', 'PRO', '203.0.113.1')->nonce;

        self::assertNotSame($first, $second);
    }

    public function testAnInvalidEmailOrPlanOpensNothing(): void
    {
        $cases = [
            ['not-an-email', 'PRO', PayFirstException::EMAIL_INVALID],
            [str_repeat('a', 120).'@example.com', 'PRO', PayFirstException::EMAIL_INVALID],
            ['ada@example.com', 'ENTERPRISE', PayFirstException::PLAN_INVALID],
            ['ada@example.com', 'TEAM', PayFirstException::PLAN_INVALID],
        ];
        foreach ($cases as [$email, $plan, $code]) {
            try {
                $this->checkout(team: '')->start($email, $plan, '203.0.113.1');
                self::fail(sprintf('%s / %s must be refused', $email, $plan));
            } catch (PayFirstException $e) {
                self::assertSame($code, $e->getErrorCode());
            }
        }
        self::assertSame([], $this->gateway->created);
    }

    public function testATierTheOperatorDeactivatedIsNotSold(): void
    {
        $hidden = $this->createStub(Subscription::class);
        $hidden->method('isActive')->willReturn(false);

        try {
            $this->checkout(proRow: $hidden)->start('ada@example.com', 'PRO', '203.0.113.1');
            self::fail('A deactivated tier must not open Checkout');
        } catch (PayFirstException $e) {
            self::assertSame(PayFirstException::PLAN_INVALID, $e->getErrorCode());
        }
        self::assertSame([], $this->gateway->created);
    }

    public function testOneConnectionIsLimitedBeforeStripeIsCalled(): void
    {
        $checkout = $this->checkout(perIp: 2);
        $checkout->start('a@example.com', 'PRO', '203.0.113.1');
        $checkout->start('b@example.com', 'PRO', '203.0.113.1');

        try {
            $checkout->start('c@example.com', 'PRO', '203.0.113.1');
            self::fail('The third start from one connection must be refused');
        } catch (PayFirstException $e) {
            self::assertSame(PayFirstException::RATE_LIMITED, $e->getErrorCode());
            self::assertSame(429, $e->getHttpStatus());
            self::assertGreaterThan(0, $e->getRetryAfterSeconds());
        }
        self::assertCount(2, $this->gateway->created);
    }

    public function testOneAddressIsLimitedWhateverItsSpelling(): void
    {
        $checkout = $this->checkout(perEmail: 1);
        $checkout->start('Ada@Example.com', 'PRO', '203.0.113.1');

        $this->expectException(PayFirstException::class);
        $checkout->start('ada@example.com', 'PRO', '198.51.100.7');
    }

    private function checkout(int $perIp = 100, int $perEmail = 100, string $team = 'price_team', ?Subscription $proRow = null): PayFirstCheckout
    {
        $billing = $this->createStub(BillingService::class);
        $billing->method('isEnabled')->willReturn(true);
        $catalogue = $this->createStub(SubscriptionRepository::class);
        $catalogue->method('findByLevelAnyState')->willReturnCallback(static fn (string $level): ?Subscription => 'PRO' === $level ? $proRow : null);

        return new PayFirstCheckout(
            $this->gateway,
            new StripeEntitlement($this->createStub(SignupAnomalyAlert::class), new NullLogger(), 'price_pro', $team, 'price_business'),
            new PlanAvailability($billing, $catalogue),
            new RateLimiterFactory(['id' => 'checkout_start_ip', 'policy' => 'sliding_window', 'limit' => $perIp, 'interval' => '1 hour'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'checkout_start_email', 'policy' => 'sliding_window', 'limit' => $perEmail, 'interval' => '1 day'], new InMemoryStorage()),
            new NullLogger(),
            'https://app.example/',
            'card, link ,klarna',
            false,
        );
    }
}
