<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for SubscriptionController endpoints that do NOT make
 * outbound calls to Stripe. Outbound-call tests live in
 * SubscriptionControllerStripeOutboundTest; webhook-driven tests in
 * StripeWebhookControllerTest.
 *
 * Covered here:
 *   - GET  /api/v1/subscription/plans          — public plan catalogue
 *   - GET  /api/v1/subscription/status         — drives SubscriptionView.vue
 *   - POST /api/v1/subscription/checkout       — 400 path for invalid planId
 *
 * The status endpoint is the single contract the frontend reads when
 * rendering subscription state, so we pin both the "no subscription" and
 * "active subscription with paymentFailed flag" shapes — that flag exists
 * in the API response today even though the UI ignores it (tracked as a
 * separate GitHub issue).
 */
class SubscriptionControllerEndpointTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const PRICE_PRO = 'price_1TestSuitePro';
    private const PRICE_TEAM = 'price_1TestSuiteTeam';
    private const PRICE_BUSINESS = 'price_1TestSuiteBusiness';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $accessToken;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $this->user = new User();
        $this->user->setMail('subscription-endpoint-'.bin2hex(random_bytes(6)).'@test.synaplan.com');
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

    public function testGetPlansReturnsAllThreeTiersWithStripePriceIds(): void
    {
        $this->client->request('GET', '/api/v1/subscription/plans');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($body['stripeConfigured'], 'Test env has real-shape Stripe price IDs configured');
        $this->assertCount(3, $body['plans'], 'Catalogue must always expose exactly PRO/TEAM/BUSINESS');

        $byId = [];
        foreach ($body['plans'] as $plan) {
            $byId[$plan['id']] = $plan;
        }
        $this->assertSame(['PRO', 'TEAM', 'BUSINESS'], array_keys($byId), 'Plan order must be cheap-to-expensive');

        $this->assertSame(self::PRICE_PRO, $byId['PRO']['stripePriceId']);
        $this->assertSame(self::PRICE_TEAM, $byId['TEAM']['stripePriceId']);
        $this->assertSame(self::PRICE_BUSINESS, $byId['BUSINESS']['stripePriceId']);

        // Ascending price tiers — pricing-config drift would flip this and we'd want CI to catch it.
        $this->assertLessThan($byId['TEAM']['price'], $byId['PRO']['price']);
        $this->assertLessThan($byId['BUSINESS']['price'], $byId['TEAM']['price']);

        // The frontend renders these arrays verbatim; missing keys would break the cards.
        foreach ($byId as $plan) {
            $this->assertArrayHasKey('features', $plan);
            $this->assertNotEmpty($plan['features']);
            $this->assertSame('EUR', $plan['currency']);
            $this->assertSame('month', $plan['interval']);
        }
    }

    public function testGetSubscriptionStatusReturns401WhenUnauthenticated(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/subscription/status');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetSubscriptionStatusForUserWithoutSubscription(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/subscription/status',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertFalse($body['hasSubscription']);
        $this->assertSame('NEW', $body['plan']);
        // None of the subscription fields must leak when there's no subscription.
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('nextBilling', $body);
        $this->assertArrayNotHasKey('cancelAt', $body);
        $this->assertArrayNotHasKey('paymentFailed', $body);
    }

    public function testGetSubscriptionStatusReflectsPaymentFailedFlag(): void
    {
        $subscriptionId = 'sub_status_'.bin2hex(random_bytes(4));
        $nextBilling = time() + 30 * 86400;

        $details = $this->user->getPaymentDetails();
        $details['subscription'] = [
            'stripe_subscription_id' => $subscriptionId,
            'status' => 'past_due',
            'subscription_start' => time() - 86400,
            'subscription_end' => $nextBilling,
            'plan' => 'PRO',
            'payment_failed' => true,
            'payment_failed_at' => time() - 3600,
        ];
        $this->user->setPaymentDetails($details);
        $this->user->setUserLevel('PRO');
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/subscription/status',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        // This is the contract the frontend (today incompletely) consumes:
        // — plan reflects the user level, NOT the cached subscription.plan
        // — paymentFailed surfaces the dunning state for the (planned) UI banner
        // — nextBilling is the period_end timestamp the SubscriptionView formats
        $this->assertTrue($body['hasSubscription']);
        $this->assertSame('PRO', $body['plan']);
        $this->assertSame('past_due', $body['status']);
        $this->assertSame($nextBilling, $body['nextBilling']);
        $this->assertTrue($body['paymentFailed']);
        $this->assertSame($subscriptionId, $body['stripeSubscriptionId']);
        $this->assertNull($body['cancelAt']);
    }

    public function testGetBudgetReturns401WhenUnauthenticated(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/subscription/budget');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetBudgetReturnsDocumentedShape(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/subscription/budget',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken],
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        // This is the exact contract the frontend Zod schema
        // (GetSubscriptionBudgetResponseSchema) validates — keep it in lockstep
        // with the OpenAPI annotation on SubscriptionController::budget().
        foreach ([
            'allowed', 'used_cost', 'raw_cost', 'markup_percent', 'base_budget',
            'topups', 'budget', 'remaining', 'percent', 'period_start',
            'period_end', 'gate_enabled', 'topup_step_eur', 'billing_enabled',
        ] as $key) {
            $this->assertArrayHasKey($key, $body, "budget response must expose '$key'");
        }

        $this->assertIsBool($body['allowed']);
        $this->assertIsBool($body['gate_enabled']);
        $this->assertIsBool($body['billing_enabled']);
        $this->assertIsInt($body['topup_step_eur']);
        $this->assertIsInt($body['period_start']);
        $this->assertIsInt($body['period_end']);
        // Money fields are serialised as strings (number_format) so the frontend
        // never has to worry about float rounding.
        $this->assertIsString($body['budget']);
        $this->assertIsString($body['remaining']);
    }

    public function testCheckoutReturns400ForInvalidPlanId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/subscription/checkout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken,
            ],
            json_encode(['planId' => 'ENTERPRISE'], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid plan', $body['error']);

        // The user must NOT have been linked to a Stripe customer when the
        // request is rejected up-front — the 400 path must short-circuit
        // before Customer::create runs.
        $this->em->refresh($this->user);
        $this->assertNull($this->user->getStripeCustomerId());
    }
}
