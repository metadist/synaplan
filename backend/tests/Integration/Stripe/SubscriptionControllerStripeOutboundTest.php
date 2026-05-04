<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe;

use App\Entity\User;
use App\Tests\Integration\Stripe\Mock\StripeMockHttpClient;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for SubscriptionController's outbound Stripe API calls.
 *
 * These endpoints call the Stripe SDK with side-effects on Stripe's
 * infrastructure (Customer creation, Checkout / Portal session creation,
 * Subscription updates, Subscription::all). CI cannot make those calls for
 * real, so we replace the Stripe SDK's HTTP client with StripeMockHttpClient
 * via \Stripe\ApiRequestor::setHttpClient() and verify:
 *   - the controller actually issues the right method + endpoint
 *   - the response body and DB state reflect the (mocked) Stripe response
 *
 * Production controller code is NOT modified; the Stripe SDK exposes
 * setHttpClient() specifically for this scenario.
 *
 * Webhook-driven scenarios (signature, idempotency, lifecycle without
 * outbound calls) live in StripeWebhookControllerTest.
 */
class SubscriptionControllerStripeOutboundTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const PRICE_PRO = 'price_1TestSuitePro';
    private const PRICE_BUSINESS = 'price_1TestSuiteBusiness';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $accessToken;
    private StripeMockHttpClient $stripeMock;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $this->stripeMock = new StripeMockHttpClient();
        ApiRequestor::setHttpClient($this->stripeMock);

        $this->user = new User();
        $this->user->setMail('stripe-outbound-'.bin2hex(random_bytes(6)).'@test.synaplan.com');
        $this->user->setPw(password_hash('Test1234!', PASSWORD_BCRYPT));
        $this->user->setUserLevel('NEW');
        $this->user->setProviderId('local');
        $this->user->setCreated(date('YmdHis'));

        $this->em->persist($this->user);
        $this->em->flush();

        $this->accessToken = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
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

    public function testCheckoutCreatesNewStripeCustomerWhenUserHasNone(): void
    {
        $newCustomerId = 'cus_'.bin2hex(random_bytes(8));
        $sessionId = 'cs_test_'.bin2hex(random_bytes(8));

        // 1. Customer::create (no customer yet on the user)
        $this->stripeMock->expect('POST', 'customers', [
            'id' => $newCustomerId,
            'object' => 'customer',
            'email' => $this->user->getMail(),
        ]);

        // 2. Checkout\Session::create
        $this->stripeMock->expect('POST', 'checkout/sessions', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
            'customer' => $newCustomerId,
        ]);

        $this->postJson('/api/v1/subscription/checkout', ['planId' => 'PRO']);

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($sessionId, $body['sessionId']);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $body['url']);

        // Customer ID must be persisted on the user.
        $this->em->refresh($this->user);
        $this->assertSame($newCustomerId, $this->user->getStripeCustomerId());

        // Both Stripe endpoints must have been called exactly once.
        $this->assertSame(1, $this->stripeMock->countCalls('POST', 'customers'));
        $this->assertSame(1, $this->stripeMock->countCalls('POST', 'checkout/sessions'));
    }

    public function testCheckoutReusesExistingStripeCustomer(): void
    {
        $customerId = 'cus_existing_'.bin2hex(random_bytes(4));
        $this->seedStripeCustomerId($customerId);
        $sessionId = 'cs_test_'.bin2hex(random_bytes(8));

        // Customer::retrieve verifies the customer still exists.
        $this->stripeMock->expect('GET', 'customers/'.$customerId, [
            'id' => $customerId,
            'object' => 'customer',
            'email' => $this->user->getMail(),
        ]);

        $this->stripeMock->expect('POST', 'checkout/sessions', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
            'customer' => $customerId,
        ]);

        $this->postJson('/api/v1/subscription/checkout', ['planId' => 'BUSINESS']);

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->stripeMock->countCalls('GET', 'customers/'.$customerId));
        $this->assertSame(0, $this->stripeMock->countCalls('POST', 'customers'),
            'Existing customer must not be duplicated');
    }

    public function testCancelSubscriptionSchedulesCancelAtPeriodEnd(): void
    {
        $subscriptionId = 'sub_'.bin2hex(random_bytes(8));
        $this->seedStripeCustomerId('cus_'.bin2hex(random_bytes(4)));
        $this->seedActiveSubscription($subscriptionId);

        $cancelAt = time() + 30 * 86400;

        // Stripe SDK does Subscription::update via POST /v1/subscriptions/{id}
        $this->stripeMock->expect('POST', 'subscriptions/'.$subscriptionId, [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => $cancelAt,
        ]);

        $this->postJson('/api/v1/subscription/cancel');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame($cancelAt, $body['cancelAt']);

        $this->em->refresh($this->user);
        $sub = $this->user->getPaymentDetails()['subscription'];
        $this->assertSame('canceling', $sub['status']);
        $this->assertSame($cancelAt, $sub['cancel_at']);

        // Verify the controller passed cancel_at_period_end=true to Stripe.
        $captured = $this->stripeMock->captured();
        $updateCall = null;
        foreach ($captured as $call) {
            if ('post' === $call['method'] && str_contains($call['url'], 'subscriptions/'.$subscriptionId)) {
                $updateCall = $call;
                break;
            }
        }
        $this->assertNotNull($updateCall);
        // Stripe SDK serialises booleans into the form-encoded body as the
        // string 'true', so we accept either representation.
        $this->assertContains($updateCall['params']['cancel_at_period_end'], [true, 'true']);
    }

    public function testCancelSubscriptionReturns404WhenNoActiveSubscription(): void
    {
        $this->postJson('/api/v1/subscription/cancel');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertSame(0, count($this->stripeMock->captured()),
            'No Stripe call must be issued when there is nothing to cancel');
    }

    public function testCreatePortalSessionReturnsUrl(): void
    {
        $customerId = 'cus_'.bin2hex(random_bytes(8));
        $this->seedStripeCustomerId($customerId);

        $this->stripeMock->expect('POST', 'billing_portal/sessions', [
            'id' => 'bps_'.bin2hex(random_bytes(4)),
            'object' => 'billing_portal.session',
            'url' => 'https://billing.stripe.com/p/session/test_xxx',
            'customer' => $customerId,
            'return_url' => 'http://localhost:8000/subscription',
        ]);

        $this->postJson('/api/v1/subscription/portal');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringStartsWith('https://billing.stripe.com/', $body['url']);
        $this->assertSame(1, $this->stripeMock->countCalls('POST', 'billing_portal/sessions'));
    }

    public function testCreatePortalSessionReturns404WhenNoStripeCustomer(): void
    {
        $this->postJson('/api/v1/subscription/portal');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertSame(0, count($this->stripeMock->captured()),
            'Portal must not call Stripe when the user has no customer id yet');
    }

    public function testSyncFromStripeUpgradesUserToHighestActivePlan(): void
    {
        $customerId = 'cus_'.bin2hex(random_bytes(8));
        $this->seedStripeCustomerId($customerId);
        $businessSubId = 'sub_business_'.bin2hex(random_bytes(4));
        $proSubId = 'sub_pro_'.bin2hex(random_bytes(4));
        $now = time();

        // Subscription::all returns two active subs (PRO + BUSINESS); the
        // controller must pick BUSINESS as the highest tier.
        $this->stripeMock->expect('GET', 'subscriptions', [
            'object' => 'list',
            'has_more' => false,
            'url' => '/v1/subscriptions',
            'data' => [
                $this->stripeSubscriptionPayload($proSubId, $customerId, self::PRICE_PRO, $now),
                $this->stripeSubscriptionPayload($businessSubId, $customerId, self::PRICE_BUSINESS, $now),
            ],
        ]);

        $this->postJson('/api/v1/subscription/sync');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('BUSINESS', $body['level']);
        $this->assertSame($businessSubId, $body['subscriptionId']);

        $this->em->refresh($this->user);
        $this->assertSame('BUSINESS', $this->user->getUserLevel());
        $this->assertSame($businessSubId, $this->user->getPaymentDetails()['subscription']['stripe_subscription_id']);
    }

    public function testSyncFromStripeDowngradesToNewWhenNoActiveSubscriptions(): void
    {
        $customerId = 'cus_'.bin2hex(random_bytes(8));
        $this->seedStripeCustomerId($customerId);
        $this->seedActiveSubscription('sub_stale_'.bin2hex(random_bytes(4)));

        $this->stripeMock->expect('GET', 'subscriptions', [
            'object' => 'list',
            'has_more' => false,
            'url' => '/v1/subscriptions',
            'data' => [],
        ]);

        $this->postJson('/api/v1/subscription/sync');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('NEW', $body['level']);
        $this->assertSame('no_active_subscription', $body['status']);

        $this->em->refresh($this->user);
        $this->assertSame('NEW', $this->user->getUserLevel());
        // The cached subscription record's status flips to canceled — this is
        // what protects against re-asserting PRO if a later /status call
        // raced ahead of a webhook.
        $this->assertSame('canceled', $this->user->getPaymentDetails()['subscription']['status']);
    }

    public function testSyncFromStripeReturnsEarlyForUserWithoutCustomer(): void
    {
        $this->postJson('/api/v1/subscription/sync');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('NEW', $body['level']);
        $this->assertSame('no_customer', $body['status']);
        $this->assertSame(0, count($this->stripeMock->captured()),
            'No Stripe call must be issued for users without a customer id');
    }

    private function seedStripeCustomerId(string $customerId): void
    {
        $this->user->setStripeCustomerId($customerId);
        $this->em->flush();
    }

    private function seedActiveSubscription(string $subscriptionId): void
    {
        $details = $this->user->getPaymentDetails();
        $details['subscription'] = [
            'stripe_subscription_id' => $subscriptionId,
            'status' => 'active',
            'subscription_start' => time() - 86400,
            'subscription_end' => time() + 30 * 86400,
            'plan' => 'PRO',
        ];
        $this->user->setPaymentDetails($details);
        $this->user->setUserLevel('PRO');
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeSubscriptionPayload(string $id, string $customerId, string $priceId, int $now): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customerId,
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
                        'id' => $priceId,
                        'object' => 'price',
                    ],
                ]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $uri, array $body = []): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken,
            ],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
