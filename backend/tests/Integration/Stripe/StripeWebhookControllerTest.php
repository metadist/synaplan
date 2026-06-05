<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe;

use App\Entity\User;
use App\Tests\Integration\Stripe\Mock\StripeMockHttpClient;
use App\Tests\Integration\Stripe\Mock\StripeSignatureHelper;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for StripeWebhookController.
 *
 * Covers the backend-only behaviour that is intentionally NOT in the E2E
 * Playwright suite (no user-observable UI difference, or hard to assert
 * deterministically through the browser):
 *   - signature verification (invalid / missing)
 *   - idempotency (duplicate event.id)
 *   - 2xx contract for unhandled event types (so Stripe doesn't retry-storm)
 *   - subscription.paused / subscription.resumed
 *   - subscription.updated with status=canceled (status change without
 *     implicit downgrade)
 *   - invoice.payment_failed (paymentFailed flag is set even though the UI
 *     doesn't yet visualise it — UI gap is tracked separately)
 *
 * Stripe outbound calls (cancelOtherSubscriptions inside subscription.created)
 * are stubbed via StripeMockHttpClient — no real HTTP.
 *
 * Webhook signatures are computed locally with the test webhook secret
 * (`whsec_fakeWebhookSecretForTests`) so the controller's
 * \Stripe\Webhook::constructEvent() accepts our crafted payloads.
 *
 * `phpunit.xml.dist` forces STRIPE_WEBHOOK_SECRET to the same value: Docker
 * injects real Stripe env vars from backend/.env, and tests/bootstrap.php uses
 * Dotenv::load() for .env.test which does not override existing variables.
 */
class StripeWebhookControllerTest extends WebTestCase
{
    private const WEBHOOK_SECRET = 'whsec_fakeWebhookSecretForTests';
    private const PRICE_PRO = 'price_1TestSuitePro';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $stripeCustomerId;
    private StripeMockHttpClient $stripeMock;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Replace the Stripe SDK's HTTP client with our recording mock so the
        // SDK never reaches api.stripe.com during tests. Reset in tearDown so
        // a stale mock doesn't bleed into other test files.
        $this->stripeMock = new StripeMockHttpClient();
        ApiRequestor::setHttpClient($this->stripeMock);

        // Unique customer id per test → tests don't see each other's state.
        $this->stripeCustomerId = 'cus_test_'.bin2hex(random_bytes(8));

        $this->user = new User();
        $this->user->setMail('stripe-webhook-'.bin2hex(random_bytes(6)).'@test.synaplan.com');
        $this->user->setPw(password_hash('Test1234!', PASSWORD_BCRYPT));
        $this->user->setUserLevel('NEW');
        $this->user->setProviderId('local');
        $this->user->setCreated(date('YmdHis'));
        $this->user->setStripeCustomerId($this->stripeCustomerId);

        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Reset the Stripe SDK back to its default cURL client so an unrelated
        // test class does not inherit our mock. We pass a fresh CurlClient
        // instance because setHttpClient()'s type contract does not allow null
        // even though the SDK lazy-initialises a CurlClient when none is set.
        ApiRequestor::setHttpClient(CurlClient::instance());

        if (isset($this->user) && $this->em->isOpen()) {
            $managed = $this->em->find(User::class, $this->user->getId());
            if ($managed) {
                $this->em->remove($managed);
                $this->em->flush();
            }
        }

        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testInvalidSignatureReturns400(): void
    {
        $payload = $this->buildEventPayload('customer.subscription.deleted', $this->subscriptionObject([]));

        $this->postWebhook($payload, 'invalid_signature_format');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        // No state change.
        $this->em->refresh($this->user);
        $this->assertSame('NEW', $this->user->getUserLevel());
    }

    public function testMissingSignatureReturns400(): void
    {
        $payload = $this->buildEventPayload('customer.subscription.deleted', $this->subscriptionObject([]));

        $this->client->request(
            'POST',
            '/api/v1/stripe/webhook',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('No signature', $body['error']);
    }

    public function testIdempotencyDuplicateEventReturnsAlreadyProcessed(): void
    {
        $this->seedActiveSubscription('sub_idem_'.bin2hex(random_bytes(4)));

        $eventId = 'evt_idem_'.bin2hex(random_bytes(4));
        $subscription = $this->subscriptionObject([
            'id' => $this->user->getPaymentDetails()['subscription']['stripe_subscription_id'],
            'status' => 'active',
        ]);
        $payload = $this->buildEventPayload(
            'customer.subscription.updated',
            $subscription,
            $eventId,
        );

        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($first['success']);
        $this->assertArrayNotHasKey('status', $first, 'First call should be processed normally');

        // Second call with same event.id → controller short-circuits.
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($second['success']);
        $this->assertSame('already_processed', $second['status']);
    }

    public function testUnhandledEventTypeReturns2xxWithoutStateChange(): void
    {
        // customer.created has no handler in the match() — must still 200 to
        // prevent Stripe retry storms, and must not touch user level.
        $payload = $this->buildEventPayload('customer.created', [
            'id' => $this->stripeCustomerId,
            'object' => 'customer',
            'email' => $this->user->getMail(),
        ]);

        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);

        $this->em->refresh($this->user);
        $this->assertSame('NEW', $this->user->getUserLevel());
        $this->assertNull($this->user->getPaymentDetails()['subscription'] ?? null);
    }

    public function testSubscriptionPausedSetsStatusFlag(): void
    {
        $subscriptionId = 'sub_pause_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        $payload = $this->buildEventPayload(
            'customer.subscription.paused',
            $this->subscriptionObject(['id' => $subscriptionId, 'status' => 'paused']),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        // Level stays PRO during a pause — feature access continues until the
        // app explicitly downgrades; the status flag drives any UI banner.
        $this->assertSame('PRO', $this->user->getUserLevel());
        $this->assertSame('paused', $sub['status']);
        $this->assertArrayHasKey('paused_at', $sub);
        $this->assertIsInt($sub['paused_at']);
    }

    public function testSubscriptionResumedClearsPausedFlag(): void
    {
        $subscriptionId = 'sub_resume_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId, ['status' => 'paused', 'paused_at' => time() - 3600]);

        $payload = $this->buildEventPayload(
            'customer.subscription.resumed',
            $this->subscriptionObject(['id' => $subscriptionId, 'status' => 'active']),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('active', $sub['status']);
        $this->assertArrayNotHasKey('paused_at', $sub);
    }

    public function testSubscriptionUpdatedWithStatusCanceledKeepsLevelAndUpdatesStatus(): void
    {
        $subscriptionId = 'sub_canceledstatus_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        // Stripe sometimes sends subscription.updated with status=canceled
        // BEFORE customer.subscription.deleted (or instead of). The handler
        // must propagate the status without implicitly downgrading the user;
        // only .deleted owns the downgrade.
        $payload = $this->buildEventPayload(
            'customer.subscription.updated',
            $this->subscriptionObject([
                'id' => $subscriptionId,
                'status' => 'canceled',
            ]),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $this->assertSame('PRO', $this->user->getUserLevel(), 'Level must NOT downgrade on status=canceled without .deleted event');
        $this->assertSame('canceled', $this->user->getPaymentDetails()['subscription']['status']);
    }

    public function testInvoicePaymentFailedSetsPaymentFailedFlag(): void
    {
        $subscriptionId = 'sub_payfail_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        $payload = $this->buildEventPayload('invoice.payment_failed', [
            'id' => 'in_'.bin2hex(random_bytes(4)),
            'object' => 'invoice',
            'customer' => $this->stripeCustomerId,
            'subscription' => $subscriptionId,
            'amount_due' => 1995,
        ]);
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        // Level stays PRO — Stripe's smart-retry / dunning flow may still
        // recover the payment. The flag lets the UI surface a banner.
        $this->assertSame('PRO', $this->user->getUserLevel());
        $this->assertTrue($sub['payment_failed']);
        $this->assertIsInt($sub['payment_failed_at']);
    }

    public function testInvoicePaymentSucceededClearsPaymentFailedFlag(): void
    {
        // Issue #856 recovery criterion 1: when Stripe's dunning eventually
        // recovers the card and fires invoice.payment_succeeded, the flag
        // we set on payment_failed must clear so the SubscriptionView
        // warning banner disappears on the next reload.
        $subscriptionId = 'sub_payrecov_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        // Pre-state: simulate a previously-failed invoice.
        $details = $this->user->getPaymentDetails();
        $details['subscription']['payment_failed'] = true;
        $details['subscription']['payment_failed_at'] = time() - 3600;
        $this->user->setPaymentDetails($details);
        $this->em->flush();

        $payload = $this->buildEventPayload('invoice.payment_succeeded', [
            'id' => 'in_'.bin2hex(random_bytes(4)),
            'object' => 'invoice',
            'customer' => $this->stripeCustomerId,
            'subscription' => $subscriptionId,
            'amount_paid' => 1995,
        ]);
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertArrayNotHasKey('payment_failed', $sub, 'payment_failed flag must be cleared');
        $this->assertArrayNotHasKey('payment_failed_at', $sub, 'payment_failed_at timestamp must be cleared');
    }

    public function testSubscriptionUpdatedToActiveClearsPaymentFailedFlag(): void
    {
        // Issue #856 recovery criterion 2: Stripe's smart-retry can also
        // resolve the past_due invoice via subscription.updated (status →
        // active) without firing invoice.payment_succeeded. The flag must
        // still clear so the warning banner disappears.
        $subscriptionId = 'sub_resolved_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        $details = $this->user->getPaymentDetails();
        $details['subscription']['payment_failed'] = true;
        $details['subscription']['payment_failed_at'] = time() - 7200;
        $this->user->setPaymentDetails($details);
        $this->em->flush();

        $payload = $this->buildEventPayload(
            'customer.subscription.updated',
            $this->subscriptionObject([
                'id' => $subscriptionId,
                'status' => 'active',
            ]),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('active', $sub['status']);
        $this->assertArrayNotHasKey('payment_failed', $sub);
        $this->assertArrayNotHasKey('payment_failed_at', $sub);
    }

    public function testSubscriptionUpdatedToPastDueDoesNotClearPaymentFailedFlag(): void
    {
        // Counter-test for #856: subscription.updated with status=past_due
        // means the invoice is STILL not paid. The flag set by the earlier
        // invoice.payment_failed must NOT be cleared here.
        $subscriptionId = 'sub_stillpast_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        $details = $this->user->getPaymentDetails();
        $details['subscription']['payment_failed'] = true;
        $details['subscription']['payment_failed_at'] = time() - 3600;
        $this->user->setPaymentDetails($details);
        $this->em->flush();

        $payload = $this->buildEventPayload(
            'customer.subscription.updated',
            $this->subscriptionObject([
                'id' => $subscriptionId,
                'status' => 'past_due',
            ]),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('past_due', $sub['status']);
        $this->assertTrue($sub['payment_failed'], 'payment_failed flag must persist across past_due updates');
        $this->assertIsInt($sub['payment_failed_at']);
    }

    public function testCheckoutSessionCompletedPersistsCustomerAndSessionIds(): void
    {
        // checkout.session.completed runs before customer.subscription.created
        // in the typical Stripe sequence; here it's the only event we send.
        // The handler must persist the customer + session id from the event,
        // looking up the user via client_reference_id (set during checkout
        // creation as the user's database id).
        $sessionId = 'cs_test_'.bin2hex(random_bytes(8));
        $newCustomerId = 'cus_checkout_'.bin2hex(random_bytes(6));

        // Important: we deliberately do NOT pre-set stripeCustomerId on the
        // user — this proves the handler writes it, rather than just leaving
        // an existing one in place.
        $details = $this->user->getPaymentDetails();
        unset($details['stripe_customer_id']);
        $this->user->setPaymentDetails($details);
        $this->em->flush();

        $payload = $this->buildEventPayload('checkout.session.completed', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'customer' => $newCustomerId,
            'client_reference_id' => (string) $this->user->getId(),
            'customer_email' => $this->user->getMail(),
            'mode' => 'subscription',
        ]);
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $this->assertSame($newCustomerId, $this->user->getStripeCustomerId());
        $this->assertSame($sessionId, $this->user->getPaymentDetails()['stripe_session_id']);
        // Level is set later by customer.subscription.created — checkout.session.completed
        // alone must not promote the user. This protects the upgrade flow:
        // a stray checkout-completed without a paid subscription must not unlock features.
        $this->assertSame('NEW', $this->user->getUserLevel());
    }

    public function testSubscriptionUpdatedForOldSubscriptionIdIsIgnored(): void
    {
        // This protects against a Stripe race / replay where an UPDATE for a
        // stale subscription (e.g. one already replaced during an upgrade)
        // arrives after the new subscription is already current. Without the
        // guard we'd overwrite the live subscription's status / period with
        // stale data. See StripeWebhookController::handleSubscriptionUpdated
        // — the "Only process updates for the current subscription" branch.
        $currentSubId = 'sub_current_'.bin2hex(random_bytes(4));
        $staleSubId = 'sub_stale_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($currentSubId);

        $payload = $this->buildEventPayload(
            'customer.subscription.updated',
            $this->subscriptionObject([
                'id' => $staleSubId,
                'status' => 'canceled',
                'cancel_at_period_end' => true,
                'cancel_at' => time() - 86400,
            ]),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        // None of the stale event's fields may leak into our state.
        $this->assertSame('PRO', $this->user->getUserLevel());
        $this->assertSame($currentSubId, $sub['stripe_subscription_id']);
        $this->assertSame('active', $sub['status']);
        $this->assertArrayNotHasKey('cancel_at', $sub);
        $this->assertArrayNotHasKey('cancel_at_period_end', $sub);
    }

    public function testSubscriptionDeletedDowngradesUserAndClearsSubscriptionData(): void
    {
        $subscriptionId = 'sub_del_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($subscriptionId);

        // Sanity: user starts as PRO with an active subscription.
        $this->assertSame('PRO', $this->user->getUserLevel());
        $this->assertSame('active', $this->user->getPaymentDetails()['subscription']['status']);

        $payload = $this->buildEventPayload(
            'customer.subscription.deleted',
            $this->subscriptionObject(['id' => $subscriptionId, 'status' => 'canceled']),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $this->assertSame('NEW', $this->user->getUserLevel(), 'User must be downgraded to NEW after subscription.deleted');
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('canceled', $sub['status']);
        $this->assertArrayHasKey('canceled_at', $sub);
        $this->assertIsInt($sub['canceled_at']);
        $this->assertSame($subscriptionId, $sub['stripe_subscription_id'], 'Subscription ID must be preserved for audit trail');
    }

    public function testSubscriptionDeletedForOldSubscriptionIdDoesNotDowngrade(): void
    {
        // Same race-window protection for the .deleted event: a delayed
        // delete for an OLD subscription (already superseded by an upgrade)
        // must NOT downgrade the user back to NEW. The handler explicitly
        // documents this with "// This prevents race conditions where old
        // subscription deletions reset the level".
        $currentSubId = 'sub_current_'.bin2hex(random_bytes(4));
        $staleSubId = 'sub_stale_'.bin2hex(random_bytes(4));
        $this->seedActiveSubscription($currentSubId);

        $payload = $this->buildEventPayload(
            'customer.subscription.deleted',
            $this->subscriptionObject(['id' => $staleSubId, 'status' => 'canceled']),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('PRO', $this->user->getUserLevel(), 'Stale .deleted must not downgrade the user');
        $this->assertSame($currentSubId, $sub['stripe_subscription_id']);
        $this->assertSame('active', $sub['status']);
        $this->assertArrayNotHasKey('canceled_at', $sub);
    }

    public function testSubscriptionCreatedActivatesUserAndCancelsOtherSubscriptions(): void
    {
        // The handler calls Stripe\Subscription::all(...) to find subs to
        // cancel for upgrade scenarios. We return one stale subscription that
        // must be canceled, and verify the cancel call is actually issued.
        $newSubId = 'sub_new_'.bin2hex(random_bytes(4));
        $oldSubId = 'sub_old_'.bin2hex(random_bytes(4));

        // Subscription::all → returns [old subscription]
        $this->stripeMock->expect('GET', 'subscriptions', [
            'object' => 'list',
            'data' => [[
                'id' => $oldSubId,
                'object' => 'subscription',
                'customer' => $this->stripeCustomerId,
                'status' => 'active',
            ]],
            'has_more' => false,
            'url' => '/v1/subscriptions',
        ]);

        // The SDK fetches the subscription before .cancel() in some code paths;
        // be permissive by responding to any subsequent GET on this sub id.
        $this->stripeMock->expect('GET', 'subscriptions/'.$oldSubId, [
            'id' => $oldSubId,
            'object' => 'subscription',
            'customer' => $this->stripeCustomerId,
            'status' => 'active',
        ]);

        // existing.cancel() → DELETE /v1/subscriptions/{id}
        $this->stripeMock->expect('DELETE', 'subscriptions/'.$oldSubId, [
            'id' => $oldSubId,
            'object' => 'subscription',
            'customer' => $this->stripeCustomerId,
            'status' => 'canceled',
        ]);

        $payload = $this->buildEventPayload(
            'customer.subscription.created',
            $this->subscriptionObject(['id' => $newSubId, 'status' => 'active']),
        );
        $this->postWebhook($payload, StripeSignatureHelper::header($payload, self::WEBHOOK_SECRET));
        $this->assertResponseIsSuccessful();

        $this->em->refresh($this->user);
        $this->assertSame('PRO', $this->user->getUserLevel());
        $this->assertSame($newSubId, $this->user->getPaymentDetails()['subscription']['stripe_subscription_id']);

        // The SDK can issue both list + retrieve + delete depending on the
        // exact code path; we only require that a list and a delete happened.
        $this->assertGreaterThanOrEqual(1, $this->stripeMock->countCalls('GET', 'subscriptions'));
        $this->assertGreaterThanOrEqual(1, $this->stripeMock->countCalls('DELETE', 'subscriptions/'.$oldSubId));
    }

    /**
     * Pre-populate the test user with an active PRO subscription, mirroring
     * what handleSubscriptionCreated would have written. Used by lifecycle
     * tests so they don't have to drive the full create+update flow first.
     *
     * @param array<string, mixed> $extraSubscriptionFields Merged into the subscription block
     */
    private function seedActiveSubscription(string $subscriptionId, array $extraSubscriptionFields = []): void
    {
        $details = $this->user->getPaymentDetails();
        $details['subscription'] = array_merge([
            'stripe_subscription_id' => $subscriptionId,
            'status' => 'active',
            'subscription_start' => time() - 86400,
            'subscription_end' => time() + 30 * 86400,
            'plan' => 'PRO',
        ], $extraSubscriptionFields);
        $this->user->setPaymentDetails($details);
        $this->user->setUserLevel('PRO');
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $eventDataObject The contents of `data.object`
     */
    private function buildEventPayload(string $type, array $eventDataObject, ?string $eventId = null): string
    {
        $envelope = [
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => $type,
            'livemode' => false,
            'data' => [
                'object' => $eventDataObject,
            ],
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    /**
     * Build a minimal Stripe Subscription object that's just complete enough
     * for our handlers (handleSubscription* read items[0].price.id, status,
     * cancel_at_period_end, cancel_at).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function subscriptionObject(array $overrides): array
    {
        $now = time();
        $base = [
            'id' => 'sub_default_'.bin2hex(random_bytes(4)),
            'object' => 'subscription',
            'customer' => $this->stripeCustomerId,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_'.bin2hex(random_bytes(4)),
                    'object' => 'subscription_item',
                    'current_period_start' => $now,
                    'current_period_end' => $now + 30 * 86400,
                    'price' => [
                        'id' => self::PRICE_PRO,
                        'object' => 'price',
                    ],
                ]],
            ],
        ];

        return array_merge($base, $overrides);
    }

    private function postWebhook(string $payload, string $signature): void
    {
        $this->client->request(
            'POST',
            '/api/v1/stripe/webhook',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $payload,
        );
    }
}
