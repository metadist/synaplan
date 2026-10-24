<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\User;
use App\Service\Auth\SignupAnomalyAlert;
use App\Service\Billing\StripeEntitlement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\StripeObject;

final class StripeEntitlementTest extends TestCase
{
    private const PRICE_PRO = 'price_pro';
    private const PRICE_TEAM = 'price_team';
    private const PRICE_BUSINESS = 'price_business';

    private SignupAnomalyAlert&MockObject $alert;
    private StripeEntitlement $entitlement;

    protected function setUp(): void
    {
        $this->alert = $this->createMock(SignupAnomalyAlert::class);
        $this->entitlement = new StripeEntitlement(
            $this->alert,
            new NullLogger(),
            self::PRICE_PRO,
            self::PRICE_TEAM,
            self::PRICE_BUSINESS,
        );
    }

    public function testLevelForPriceMapsConfiguredPricesAndNothingElse(): void
    {
        $this->assertSame('PRO', $this->entitlement->levelForPrice(self::PRICE_PRO));
        $this->assertSame('TEAM', $this->entitlement->levelForPrice(self::PRICE_TEAM));
        $this->assertSame('BUSINESS', $this->entitlement->levelForPrice(self::PRICE_BUSINESS));
        $this->assertSame('NEW', $this->entitlement->levelForPrice('price_unknown'));
        $this->assertSame('NEW', $this->entitlement->levelForPrice(null));
        $this->assertSame('NEW', $this->entitlement->levelForPrice(''));
    }

    public function testAnEmptyConfiguredPriceNeverMatchesAnEmptyLookup(): void
    {
        $entitlement = new StripeEntitlement($this->alert, new NullLogger(), '', '', '');

        $this->assertSame('NEW', $entitlement->levelForPrice(''));
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>, bool}>
     */
    public static function settlementCases(): iterable
    {
        yield 'active + paid invoice (card)' => ['active', ['status' => 'paid', 'paid' => true], true];
        yield 'active + open invoice with processing payment (SEPA mandate)' => ['active', ['status' => 'open', 'paid' => false], false];
        yield 'active + draft invoice' => ['active', ['status' => 'draft'], false];
        yield 'active without any invoice' => ['active', null, false];
        yield 'trialing is the operator\'s decision' => ['trialing', null, true];
        yield 'past_due + paid invoice' => ['past_due', ['status' => 'paid', 'paid' => true], false];
        yield 'incomplete' => ['incomplete', ['status' => 'open'], false];
        yield 'canceled' => ['canceled', ['status' => 'paid'], false];
    }

    /**
     * @param ?array<string, mixed> $invoice
     */
    #[DataProvider('settlementCases')]
    public function testIsSettledNeedsAnActiveSubscriptionAndAPaidInvoice(string $status, ?array $invoice, bool $expected): void
    {
        $subscription = $this->subscription(['status' => $status]);

        $this->assertSame($expected, $this->entitlement->isSettled($subscription, null === $invoice ? null : $this->object($invoice)));
    }

    public function testApplyGrantsTheTierOnlyWhenSettled(): void
    {
        $user = $this->user('NEW');
        $subscription = $this->subscription(['id' => 'sub_1', 'status' => 'active']);

        $this->alert->expects($this->never())->method('paidTierGranted');
        $this->assertFalse($this->entitlement->apply($user, $subscription, $this->object(['status' => 'open', 'paid' => false])));
        $this->assertSame('NEW', $user->getUserLevel(), 'An unsettled payment must not unlock the tier');

        $recorded = $user->getPaymentDetails()['subscription'];
        $this->assertSame('sub_1', $recorded['stripe_subscription_id']);
        $this->assertSame('active', $recorded['status']);
        $this->assertSame('BUSINESS', $recorded['plan'], 'The plan is recorded so the account can show what is pending');
        $this->assertFalse($recorded['settled']);
        $this->assertSame('stripe', $recorded['source']);
    }

    public function testApplyGrantsTheTierAndAlertsOnceThePaymentSettled(): void
    {
        $user = $this->user('NEW');
        $subscription = $this->subscription(['id' => 'sub_1', 'status' => 'active']);

        $this->alert->expects($this->once())->method('paidTierGranted')->with($user, 'BUSINESS');
        $this->assertTrue($this->entitlement->apply($user, $subscription, $this->object(['status' => 'paid', 'paid' => true])));
        $this->assertSame('BUSINESS', $user->getUserLevel());
        $this->assertTrue($user->getPaymentDetails()['subscription']['settled']);
    }

    public function testApplyNeverLowersATierAndNeverTouchesAnAdmin(): void
    {
        $pro = $this->user('PRO');
        $this->entitlement->apply($pro, $this->subscription(['id' => 'sub_x', 'status' => 'past_due']), $this->object(['status' => 'open']));
        $this->assertSame('PRO', $pro->getUserLevel(), 'Downgrades belong to the deleted/canceled handlers, not to apply()');

        $admin = $this->user('ADMIN');
        $this->alert->expects($this->never())->method('paidTierGranted');
        $this->assertTrue($this->entitlement->apply($admin, $this->subscription(['id' => 'sub_a', 'status' => 'active']), $this->object(['status' => 'paid'])));
        $this->assertSame('ADMIN', $admin->getUserLevel());
    }

    public function testApplyKeepsBookkeepingOfTheSameSubscriptionAndReplacesAnotherOne(): void
    {
        $user = $this->user('PRO');
        $user->setPaymentDetails(['subscription' => [
            'stripe_subscription_id' => 'sub_same',
            'payment_failed' => true,
            'cancel_at' => 123,
        ]]);

        $this->entitlement->apply($user, $this->subscription(['id' => 'sub_same', 'status' => 'active']), $this->object(['status' => 'paid']));
        $this->assertTrue($user->getPaymentDetails()['subscription']['payment_failed'], 'Same subscription: unrelated keys survive');

        $this->entitlement->apply($user, $this->subscription(['id' => 'sub_other', 'status' => 'active']), $this->object(['status' => 'paid']));
        $this->assertArrayNotHasKey('payment_failed', $user->getPaymentDetails()['subscription'], 'Another subscription: the block starts fresh');
        $this->assertArrayNotHasKey('cancel_at', $user->getPaymentDetails()['subscription']);
    }

    public function testHighestSettledPrefersTheHighestPaidTierAndSkipsUnsettledOnes(): void
    {
        $business = $this->subscription(['id' => 'sub_b', 'status' => 'active', 'price' => self::PRICE_BUSINESS, 'latest_invoice' => $this->object(['status' => 'open'])]);
        $team = $this->subscription(['id' => 'sub_t', 'status' => 'active', 'price' => self::PRICE_TEAM, 'latest_invoice' => $this->object(['status' => 'paid'])]);
        $pro = $this->subscription(['id' => 'sub_p', 'status' => 'active', 'price' => self::PRICE_PRO, 'latest_invoice' => $this->object(['status' => 'paid'])]);

        $best = $this->entitlement->highestSettled([$pro, $business, $team]);

        $this->assertNotNull($best);
        $this->assertSame('sub_t', $best[0]->id, 'Business is unsettled, Team is the highest settled tier');
        $this->assertSame('paid', $best[1]['status'] ?? null);
        $this->assertNull($this->entitlement->highestSettled([$business]));
    }

    public function testProofInvoiceUsesTheLatestInvoiceNotAnOlderPaidOne(): void
    {
        $latest = $this->object(['id' => 'in_new', 'status' => 'open', 'paid' => false]);
        $subscription = $this->subscription(['latest_invoice' => $latest]);
        $olderPaid = $this->object(['id' => 'in_old', 'status' => 'paid', 'paid' => true]);

        $proof = $this->entitlement->proofInvoice($subscription, $olderPaid);

        $this->assertSame('in_new', $proof->id ?? null);
        $this->assertSame('open', $proof['status'] ?? null);
        $this->assertFalse($this->entitlement->isSettled($subscription, $proof));
    }

    public function testProofInvoiceKeepsTheEventInvoiceWhenItIsTheLatest(): void
    {
        $subscription = $this->subscription(['latest_invoice' => 'in_same']);
        $event = $this->object(['id' => 'in_same', 'status' => 'paid', 'paid' => true]);

        $this->assertSame($event, $this->entitlement->proofInvoice($subscription, $event));
        $this->assertTrue($this->entitlement->isSettled($subscription, $event));
    }

    public function testLatestInvoiceReturnsTheExpandedObjectWithoutAnApiCall(): void
    {
        $expanded = $this->object(['id' => 'in_1', 'status' => 'paid']);
        $subscription = $this->subscription(['latest_invoice' => $expanded]);

        $this->assertSame($expanded, $this->entitlement->latestInvoice($subscription));
        $this->assertNull($this->entitlement->latestInvoice($this->subscription(['latest_invoice' => null])));
    }

    public function testSubscriptionOfInvoiceReadsBothApiShapes(): void
    {
        $sub = $this->subscription(['id' => 'sub_1']);

        $classic = $this->object(['subscription' => $sub]);
        $this->assertSame($sub, $this->entitlement->subscriptionOfInvoice($classic));

        $parent = $this->object(['subscription' => null, 'parent' => $this->object(['subscription_details' => $this->object(['subscription' => $sub])])]);
        $this->assertSame($sub, $this->entitlement->subscriptionOfInvoice($parent));

        $this->assertNull($this->entitlement->subscriptionOfInvoice($this->object(['subscription' => null])));
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setMail('entitlement@test.synaplan.com');
        $user->setUserLevel($level);
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setPaymentDetails([]);

        return $user;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function subscription(array $overrides = []): StripeObject
    {
        $price = $overrides['price'] ?? self::PRICE_BUSINESS;
        unset($overrides['price']);

        return $this->object(array_merge([
            'id' => 'sub_default',
            'status' => 'active',
            'items' => $this->object(['data' => [
                $this->object(['price' => $this->object(['id' => $price]), 'current_period_start' => 1, 'current_period_end' => 2]),
            ]]),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function object(array $values): StripeObject
    {
        return StripeObject::constructFrom($values);
    }
}
