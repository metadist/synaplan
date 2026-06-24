<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\BillingService;
use App\Service\IapPricingService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Subscription Management Controller
 * Handles subscription plans and Stripe checkout.
 *
 * Security:
 * - Rate limiting on checkout (prevents abuse)
 * - Authentication required for checkout/portal
 */
#[Route('/api/v1/subscription')]
#[OA\Tag(name: 'Subscription')]
class SubscriptionController extends AbstractController
{
    private const CHECKOUT_RATE_LIMIT_WINDOW = 60; // 1 minute
    private const CHECKOUT_RATE_LIMIT_MAX = 20; // max 20 checkout attempts per minute per user

    /** Fixed EUR amount per top-up "step" the user can buy. */
    public const TOPUP_STEP_EUR = 100;

    /** Max number of EUR-100 steps in a single top-up checkout. */
    private const TOPUP_MAX_STEPS = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private string $stripeSecretKey,
        private string $stripePricePro,
        private string $stripePriceTeam,
        private string $stripePriceBusiness,
        private string $frontendUrl,
        private string $stripePaymentMethods,
        private BillingService $billingService,
        private IapPricingService $iapPricingService,
        private RateLimitService $rateLimitService,
        private bool $stripeAutomaticTaxEnabled = false,
        #[Autowire(env: 'default::bool:COST_BUDGET_GATE_ENABLED')]
        private bool $costBudgetGateEnabled = false,
    ) {
    }

    /**
     * Get available subscription plans.
     */
    #[Route('/plans', name: 'subscription_plans', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/subscription/plans',
        summary: 'Get available subscription plans',
        tags: ['Subscription']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of subscription plans',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'stripePriceId', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'iapProductId',
                        type: 'string',
                        nullable: true,
                        description: 'Native in-app purchase product ID the app buys for this tier (Epic 5.5).',
                    ),
                    new OA\Property(property: 'price', type: 'number'),
                    new OA\Property(property: 'currency', type: 'string'),
                    new OA\Property(property: 'interval', type: 'string'),
                    new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        )
    )]
    public function getPlans(): JsonResponse
    {
        $plans = [
            [
                'id' => 'PRO',
                'name' => 'Pro',
                'stripePriceId' => $this->billingService->isEnabled() ? $this->stripePricePro : null,
                // MOBILE-APP SEAM (Epic 5.5): the store product the app buys for this tier.
                'iapProductId' => $this->iapPricingService->productIdForTier('PRO'),
                'price' => 19.95,
                'currency' => 'EUR',
                'interval' => 'month',
                'features' => [
                    'Unlimited Messages',
                    '100 Images/Month',
                    'Advanced AI Models',
                    'Priority Support',
                    '10GB Storage',
                ],
            ],
            [
                'id' => 'TEAM',
                'name' => 'Team',
                'stripePriceId' => $this->billingService->isEnabled() ? $this->stripePriceTeam : null,
                'iapProductId' => $this->iapPricingService->productIdForTier('TEAM'),
                'price' => 49.95,
                'currency' => 'EUR',
                'interval' => 'month',
                'features' => [
                    'Everything in Pro',
                    '500 Images/Month',
                    'Team Collaboration',
                    'Custom Prompts',
                    '50GB Storage',
                    'API Access',
                ],
            ],
            [
                'id' => 'BUSINESS',
                'name' => 'Business',
                'stripePriceId' => $this->billingService->isEnabled() ? $this->stripePriceBusiness : null,
                'iapProductId' => $this->iapPricingService->productIdForTier('BUSINESS'),
                'price' => 99.95,
                'currency' => 'EUR',
                'interval' => 'month',
                'features' => [
                    'Everything in Team',
                    'Unlimited Images',
                    'Unlimited Video Generation',
                    'White-label Widgets',
                    '200GB Storage',
                    'Dedicated Support',
                    'SLA Guarantee',
                ],
            ],
        ];

        return $this->json([
            'plans' => $plans,
            'stripeConfigured' => $this->billingService->isEnabled(),
            // MOBILE-APP SEAM (Epic 5.5): lets the app decide whether native IAP
            // is actually set up for this server before offering a purchase.
            'iapConfigured' => $this->iapPricingService->isConfigured(),
        ]);
    }

    /**
     * Create Stripe checkout session.
     */
    #[Route('/checkout', name: 'subscription_checkout', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/subscription/checkout',
        summary: 'Create Stripe checkout session',
        security: [['Bearer' => []]],
        tags: ['Subscription']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['planId'],
            properties: [
                new OA\Property(property: 'planId', type: 'string', enum: ['PRO', 'TEAM', 'BUSINESS']),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Checkout session created',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sessionId', type: 'string'),
                new OA\Property(property: 'url', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid plan')]
    #[OA\Response(response: 409, description: 'Subscription already owned by another channel (native IAP)')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    #[OA\Response(response: 503, description: 'Stripe not configured')]
    public function createCheckoutSession(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        // Rate limiting check
        if (!$this->checkCheckoutRateLimit($user)) {
            $this->logger->warning('Checkout rate limit exceeded', [
                'user_id' => $user->getId(),
            ]);

            return $this->json([
                'error' => 'Too many checkout attempts. Please wait a minute and try again.',
                'code' => 'RATE_LIMIT_EXCEEDED',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Check if Stripe is configured
        if (!$this->billingService->isEnabled()) {
            $this->logger->warning('Stripe checkout attempted but Stripe is not configured');

            return $this->json([
                'error' => 'Subscription service is currently unavailable. Please contact support.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // MOBILE-APP SEAM (Epic 5.2): block-cross. A subscription may be owned by
        // exactly one channel. If the user already has an ACTIVE subscription bought
        // through native IAP (Apple/Google), refuse to start a Stripe web checkout and
        // point them at the store where they bought it. Server-enforced so neither the
        // web nor the app can create a second, conflicting subscription.
        $existingSource = $user->getSubscriptionSource();
        if ($user->hasActiveSubscription()
            && null !== $existingSource
            && BillingService::SOURCE_STRIPE !== $existingSource) {
            return $this->json([
                'error' => 'You already have an active subscription managed by the app store. Please manage it there.',
                'code' => 'SUBSCRIPTION_OWNED_BY_OTHER_CHANNEL',
                'source' => $existingSource,
                'manageUrl' => $this->billingService->getManageUrl($existingSource),
            ], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);
        $planId = $data['planId'] ?? null;

        $priceId = match ($planId) {
            'PRO' => $this->stripePricePro,
            'TEAM' => $this->stripePriceTeam,
            'BUSINESS' => $this->stripePriceBusiness,
            default => null,
        };

        if (!$priceId) {
            return $this->json(['error' => 'Invalid plan'], Response::HTTP_BAD_REQUEST);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Create or get Stripe customer
            $customerId = $this->getOrCreateStripeCustomer($user);

            // Checkout session config. What each flag does from our side; actual tax
            // compliance depends on Stripe Dashboard setup (Stripe Tax activation,
            // tax codes on products, tax registrations) and local legal review —
            // this code alone does not guarantee a compliant invoice.
            //
            //   - billing_address_collection: 'required'
            //       Stripe Checkout collects full name + billing address incl.
            //       ISO country code.
            //
            //   - customer_update.{name, address}: 'auto'
            //       Syncs the collected name + address back onto the Stripe
            //       Customer. Note: this OVERWRITES the existing values on the
            //       Customer object for every checkout, so shared / team-billing
            //       scenarios (multiple users, one Stripe Customer) need to be
            //       tested before relying on the stored address being stable.
            //
            //   - tax_id_collection.enabled: true
            //       Lets B2B customers enter their VAT ID / EIN / ABN. Stripe
            //       performs VIES lookups for EU VAT IDs.
            //
            //   - automatic_tax.enabled: env-toggled
            //       Delegates VAT/GST calculation to Stripe Tax. Off by default
            //       so deployments without Stripe Tax activated keep working.
            //
            // Note: Can't use both 'customer' and 'customer_email' - Stripe only allows one
            $paymentMethods = array_filter(array_map('trim', explode(',', $this->stripePaymentMethods)));
            $session = \Stripe\Checkout\Session::create([
                'customer' => $customerId,
                'payment_method_types' => $paymentMethods,
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'billing_address_collection' => 'required',
                'customer_update' => [
                    'name' => 'auto',
                    'address' => 'auto',
                ],
                'tax_id_collection' => [
                    'enabled' => true,
                ],
                'automatic_tax' => [
                    'enabled' => $this->stripeAutomaticTaxEnabled,
                ],
                'allow_promotion_codes' => true,
                'success_url' => $this->frontendUrl.'/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->frontendUrl.'/subscription/cancel',
                'client_reference_id' => (string) $user->getId(),
                'metadata' => [
                    'user_id' => (string) $user->getId(),
                    'plan' => $planId,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => (string) $user->getId(),
                        'plan' => $planId,
                    ],
                ],
            ]);

            $this->logger->info('Stripe checkout session created', [
                'user_id' => $user->getId(),
                'session_id' => $session->id,
                'plan' => $planId,
            ]);

            return $this->json([
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe API error during checkout', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
                'stripe_param' => $e->getError()?->param ?? null,
            ]);

            // In dev mode, show the actual error for debugging
            $errorMessage = 'Payment service error. Please try again later.';
            if ('dev' === $_ENV['APP_ENV']) {
                $errorMessage = $e->getMessage();
            }

            return $this->json([
                'error' => $errorMessage,
                'stripe_code' => $e->getStripeCode(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create checkout session', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to create checkout session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Current cost-budget status for the signed-in user (markup-aware), so the
     * UI can show remaining budget and decide whether to offer a top-up.
     */
    #[Route('/budget', name: 'subscription_budget', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/subscription/budget',
        summary: 'Get the signed-in user\'s current cost-budget status (incl. markup + top-ups)',
        security: [['Bearer' => []]],
        tags: ['Subscription'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Budget status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'allowed', type: 'boolean'),
                new OA\Property(property: 'used_cost', type: 'string', example: '0.00'),
                new OA\Property(property: 'raw_cost', type: 'string', example: '0.00'),
                new OA\Property(property: 'markup_percent', type: 'number', format: 'float', example: 10.0),
                new OA\Property(property: 'base_budget', type: 'string', example: '10.00'),
                new OA\Property(property: 'topups', type: 'string', example: '0.00'),
                new OA\Property(property: 'budget', type: 'string', example: '10.00'),
                new OA\Property(property: 'remaining', type: 'string', example: '10.00'),
                new OA\Property(property: 'percent', type: 'number', format: 'float', example: 0.0),
                new OA\Property(property: 'period_start', type: 'integer', format: 'int64'),
                new OA\Property(property: 'period_end', type: 'integer', format: 'int64'),
                new OA\Property(property: 'gate_enabled', type: 'boolean'),
                new OA\Property(property: 'topup_step_eur', type: 'integer', example: 100),
                new OA\Property(property: 'billing_enabled', type: 'boolean'),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function budget(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $status = $this->rateLimitService->checkCostBudget($user);

        return $this->json([
            ...$status,
            'gate_enabled' => $this->costBudgetGateEnabled,
            'topup_step_eur' => self::TOPUP_STEP_EUR,
            'billing_enabled' => $this->billingService->isEnabled(),
        ]);
    }

    /**
     * Create a Stripe one-time Checkout (mode=payment) to top up the user's
     * cost budget in fixed EUR-100 steps. The webhook credits a BUSER_TOPUPS row
     * on completion, which raises the budget for the current period.
     */
    #[Route('/topup', name: 'subscription_topup', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/subscription/topup',
        summary: 'Create a one-time Stripe Checkout to top up the cost budget in EUR 100 steps',
        security: [['Bearer' => []]],
        tags: ['Subscription'],
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'steps', type: 'integer', example: 1, description: 'Number of EUR 100 steps to buy (1-50)'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Checkout session created',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sessionId', type: 'string'),
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'steps', type: 'integer', example: 1),
                new OA\Property(property: 'total_eur', type: 'integer', example: 100),
            ],
        ),
    )]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    #[OA\Response(response: 503, description: 'Stripe not configured')]
    public function createTopupSession(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->checkCheckoutRateLimit($user)) {
            return $this->json([
                'error' => 'Too many checkout attempts. Please wait a minute and try again.',
                'code' => 'RATE_LIMIT_EXCEEDED',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$this->billingService->isEnabled()) {
            return $this->json([
                'error' => 'Payment service is currently unavailable. Please contact support.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = json_decode($request->getContent(), true);
        $steps = is_array($data) ? (int) ($data['steps'] ?? 1) : 1;
        $steps = max(1, min(self::TOPUP_MAX_STEPS, $steps));
        $totalEur = $steps * self::TOPUP_STEP_EUR;

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);
            $customerId = $this->getOrCreateStripeCustomer($user);
            $paymentMethods = array_filter(array_map('trim', explode(',', $this->stripePaymentMethods)));

            $session = \Stripe\Checkout\Session::create([
                'customer' => $customerId,
                'payment_method_types' => $paymentMethods,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Synaplan usage top-up',
                            'description' => sprintf('Adds EUR %d to your usage budget for the current period.', $totalEur),
                        ],
                        'unit_amount' => self::TOPUP_STEP_EUR * 100, // cents
                    ],
                    'quantity' => $steps,
                ]],
                'mode' => 'payment',
                'billing_address_collection' => 'required',
                'customer_update' => [
                    'name' => 'auto',
                    'address' => 'auto',
                ],
                'tax_id_collection' => [
                    'enabled' => true,
                ],
                'automatic_tax' => [
                    'enabled' => $this->stripeAutomaticTaxEnabled,
                ],
                'success_url' => $this->frontendUrl.'/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->frontendUrl.'/subscription/cancel',
                'client_reference_id' => (string) $user->getId(),
                'metadata' => [
                    'user_id' => (string) $user->getId(),
                    'type' => 'topup',
                    'topup_eur' => (string) $totalEur,
                ],
            ]);

            $this->logger->info('Stripe top-up session created', [
                'user_id' => $user->getId(),
                'session_id' => $session->id,
                'steps' => $steps,
                'total_eur' => $totalEur,
            ]);

            return $this->json([
                'sessionId' => $session->id,
                'url' => $session->url,
                'steps' => $steps,
                'total_eur' => $totalEur,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe API error during top-up checkout', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Payment service error. Please try again later.'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create top-up session', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to create top-up session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get current subscription status.
     */
    #[Route('/status', name: 'subscription_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/subscription/status',
        summary: 'Get current subscription status',
        security: [['Bearer' => []]],
        tags: ['Subscription']
    )]
    #[OA\Response(
        response: 200,
        description: 'Subscription status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'hasSubscription', type: 'boolean'),
                new OA\Property(
                    property: 'active',
                    type: 'boolean',
                    description: 'Unified entitlement truth: true when any channel has a currently-valid subscription.',
                ),
                new OA\Property(property: 'plan', type: 'string'),
                new OA\Property(property: 'tier', type: 'string', description: 'Alias of `plan` (the entitled tier).'),
                new OA\Property(
                    property: 'source',
                    type: 'string',
                    enum: ['stripe', 'apple', 'google'],
                    nullable: true,
                    description: 'The channel that owns the subscription. Web buys via Stripe; the app via Apple/Google IAP. Legacy Stripe subs report `stripe`.',
                ),
                new OA\Property(
                    property: 'manageUrl',
                    type: 'string',
                    nullable: true,
                    description: 'Where to manage the subscription for IAP channels (Apple/Google system settings). Null for Stripe — use POST /subscription/portal instead.',
                ),
                new OA\Property(
                    property: 'cancelAtPeriodEnd',
                    type: 'boolean',
                    description: 'True when the subscription is set to cancel at the end of the current period.',
                ),
                new OA\Property(property: 'status', type: 'string'),
                // `nextBilling` and `cancelAt` come from Stripe webhook
                // payloads which carry Unix timestamps as integers (seconds
                // since epoch). The previous OpenAPI annotation typed them
                // as `string` and a regen of the frontend Zod schema would
                // have generated a runtime parse mismatch — Copilot review
                // on PR #931 caught the drift before it shipped.
                new OA\Property(
                    property: 'nextBilling',
                    type: 'integer',
                    format: 'int64',
                    nullable: true,
                    description: 'Unix timestamp (seconds since epoch) of the next billing date, or null if not applicable.',
                ),
                new OA\Property(
                    property: 'cancelAt',
                    type: 'integer',
                    format: 'int64',
                    nullable: true,
                    description: 'Unix timestamp (seconds since epoch) when the cancellation takes effect, or null if not scheduled.',
                ),
                new OA\Property(property: 'stripeSubscriptionId', type: 'string', nullable: true),
                new OA\Property(
                    property: 'paymentFailed',
                    type: 'boolean',
                    description: 'True when Stripe declined the last invoice. The user keeps access during Stripe\'s smart-retry window but the SubscriptionView surfaces a dedicated warning so they can update their card before access is revoked (issue #856).',
                ),
            ]
        )
    )]
    public function getSubscriptionStatus(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $paymentDetails = $user->getPaymentDetails();
        $subscription = $paymentDetails['subscription'] ?? null;

        if (!$subscription) {
            return $this->json([
                'hasSubscription' => false,
                'active' => false,
                'plan' => $user->getUserLevel(),
                'tier' => $user->getUserLevel(),
                'source' => null,
                'manageUrl' => null,
            ]);
        }

        // MOBILE-APP SEAM (Epic 5.1): unified ACTIVE truth with an explicit owning
        // channel (`source`) and a per-channel `manageUrl`. Legacy Stripe subs report
        // `source: 'stripe'` via backfill-on-read; existing keys are kept unchanged so
        // the web client and its generated schema stay backward compatible.
        $source = $user->getSubscriptionSource();

        return $this->json([
            'hasSubscription' => true,
            'active' => $user->hasActiveSubscription(),
            'plan' => $user->getUserLevel(),
            'tier' => $user->getUserLevel(),
            'source' => $source,
            'manageUrl' => $this->billingService->getManageUrl($source),
            'status' => $subscription['status'] ?? 'unknown',
            'nextBilling' => $subscription['subscription_end'] ?? null,
            'cancelAt' => $subscription['cancel_at'] ?? null,
            'cancelAtPeriodEnd' => $subscription['cancel_at_period_end'] ?? false,
            'stripeSubscriptionId' => $subscription['stripe_subscription_id'] ?? null,
            'paymentFailed' => $subscription['payment_failed'] ?? false,
        ]);
    }

    /**
     * Sync subscription status from Stripe.
     * Useful when webhooks fail or for manual resync.
     */
    #[Route('/sync', name: 'subscription_sync', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/subscription/sync',
        summary: 'Sync subscription status from Stripe',
        security: [['Bearer' => []]],
        tags: ['Subscription']
    )]
    #[OA\Response(
        response: 200,
        description: 'Subscription synced',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'level', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
            ]
        )
    )]
    public function syncFromStripe(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->billingService->isEnabled()) {
            return $this->json([
                'error' => 'Subscription service is currently unavailable.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $customerId = $user->getStripeCustomerId();
        if (!$customerId) {
            return $this->json([
                'success' => true,
                'level' => 'NEW',
                'status' => 'no_customer',
                'message' => 'No Stripe customer found',
            ]);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Get all active subscriptions for this customer
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $customerId,
                'status' => 'active',
                'limit' => 10,
            ]);

            if (empty($subscriptions->data)) {
                // No active subscriptions - downgrade to NEW
                $user->setUserLevel('NEW');
                $paymentDetails = $user->getPaymentDetails();
                if (isset($paymentDetails['subscription'])) {
                    $paymentDetails['subscription']['status'] = 'canceled';
                }
                $user->setPaymentDetails($paymentDetails);
                $this->em->flush();

                return $this->json([
                    'success' => true,
                    'level' => 'NEW',
                    'status' => 'no_active_subscription',
                    'message' => 'No active subscription found in Stripe',
                ]);
            }

            // Find the highest tier subscription
            $highestLevel = 'NEW';
            $activeSubscription = null;
            $levelPriority = ['NEW' => 0, 'PRO' => 1, 'TEAM' => 2, 'BUSINESS' => 3];

            foreach ($subscriptions->data as $sub) {
                $priceId = $sub->items->data[0]->price->id ?? null;
                $level = $this->mapPriceIdToLevel($priceId);

                if (($levelPriority[$level] ?? 0) > ($levelPriority[$highestLevel] ?? 0)) {
                    $highestLevel = $level;
                    $activeSubscription = $sub;
                }
            }

            // Update user with the highest tier
            $user->setUserLevel($highestLevel);

            $paymentDetails = $user->getPaymentDetails();
            $firstItem = $activeSubscription->items->data[0] ?? null;
            // Stripe SDK v20 dropped the typed `current_period_start/end` properties from
            // SubscriptionItem; the values still ship in the API response and are reachable
            // via StripeObject's ArrayAccess, which PHPStan accepts.
            $paymentDetails['subscription'] = [
                'source' => BillingService::SOURCE_STRIPE,
                'stripe_subscription_id' => $activeSubscription->id,
                'status' => $activeSubscription->status,
                'subscription_start' => $firstItem['current_period_start'] ?? null,
                'subscription_end' => $firstItem['current_period_end'] ?? null,
                'plan' => $highestLevel,
                'cancel_at_period_end' => $activeSubscription->cancel_at_period_end ?? false,
            ];
            if ($activeSubscription->cancel_at) {
                $paymentDetails['subscription']['cancel_at'] = $activeSubscription->cancel_at;
            }
            $user->setPaymentDetails($paymentDetails);

            $this->em->flush();

            $this->logger->info('Subscription synced from Stripe', [
                'user_id' => $user->getId(),
                'level' => $highestLevel,
                'subscription_id' => $activeSubscription->id,
            ]);

            return $this->json([
                'success' => true,
                'level' => $highestLevel,
                'status' => $activeSubscription->status,
                'subscriptionId' => $activeSubscription->id,
                'message' => 'Subscription synced successfully',
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe API error during sync', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to sync from Stripe: '.$e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Map Stripe price ID to user level.
     */
    private function mapPriceIdToLevel(?string $priceId): string
    {
        if (!$priceId) {
            return 'NEW';
        }

        return match ($priceId) {
            $this->stripePricePro => 'PRO',
            $this->stripePriceTeam => 'TEAM',
            $this->stripePriceBusiness => 'BUSINESS',
            default => 'NEW',
        };
    }

    /**
     * Create Stripe Customer Portal session.
     */
    #[Route('/portal', name: 'subscription_portal', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/subscription/portal',
        summary: 'Create Stripe Customer Portal session',
        security: [['Bearer' => []]],
        tags: ['Subscription']
    )]
    #[OA\Response(
        response: 200,
        description: 'Portal session created',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'url', type: 'string'),
            ]
        )
    )]
    public function createPortalSession(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->billingService->isEnabled()) {
            return $this->json([
                'error' => 'Subscription service is currently unavailable.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Get customer ID from paymentDetails JSON
            $customerId = $user->getStripeCustomerId();

            if (!$customerId) {
                return $this->json(['error' => 'No active subscription found'], Response::HTTP_NOT_FOUND);
            }

            // Create portal session
            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $customerId,
                'return_url' => $this->frontendUrl.'/subscription',
            ]);

            $this->logger->info('Stripe portal session created', [
                'user_id' => $user->getId(),
                'session_id' => $session->id,
            ]);

            return $this->json([
                'url' => $session->url,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe API error during portal creation', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Payment service error. Please try again later.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create portal session', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to create portal session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel subscription.
     */
    #[Route('/cancel', name: 'subscription_cancel', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/subscription/cancel',
        summary: 'Cancel current subscription',
        security: [['Bearer' => []]],
        tags: ['Subscription']
    )]
    #[OA\Response(response: 200, description: 'Subscription cancelled')]
    #[OA\Response(response: 404, description: 'No active subscription')]
    public function cancelSubscription(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->billingService->isEnabled()) {
            return $this->json([
                'error' => 'Subscription service is currently unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $paymentDetails = $user->getPaymentDetails();
        $subscriptionId = $paymentDetails['subscription']['stripe_subscription_id'] ?? null;

        if (!$subscriptionId) {
            return $this->json(['error' => 'No active subscription found'], Response::HTTP_NOT_FOUND);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Cancel at period end (user keeps access until end of billing period)
            $subscription = \Stripe\Subscription::update($subscriptionId, [
                'cancel_at_period_end' => true,
            ]);

            $paymentDetails['subscription']['cancel_at'] = $subscription->cancel_at;
            $paymentDetails['subscription']['status'] = 'canceling';
            $user->setPaymentDetails($paymentDetails);
            $this->em->flush();

            $this->logger->info('Subscription cancellation scheduled', [
                'user_id' => $user->getId(),
                'subscription_id' => $subscriptionId,
                'cancel_at' => $subscription->cancel_at,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Your subscription will be cancelled at the end of the current billing period.',
                'cancelAt' => $subscription->cancel_at,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe API error during cancellation', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to cancel subscription. Please try again or contact support.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Rate limiting for checkout endpoint.
     */
    private function checkCheckoutRateLimit(User $user): bool
    {
        $cacheKey = 'checkout_rate_'.$user->getId();

        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            $item->set(1);
            $item->expiresAfter(self::CHECKOUT_RATE_LIMIT_WINDOW);
            $this->cache->save($item);

            return true;
        }

        $count = $item->get();
        if ($count >= self::CHECKOUT_RATE_LIMIT_MAX) {
            return false;
        }

        $item->set($count + 1);
        $this->cache->save($item);

        return true;
    }

    /**
     * Get or create Stripe customer for user.
     */
    private function getOrCreateStripeCustomer(User $user): string
    {
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);

        // Check if customer ID exists in paymentDetails JSON
        $customerId = $user->getStripeCustomerId();

        if ($customerId) {
            // Verify customer still exists in Stripe (may have been deleted or wrong account)
            try {
                \Stripe\Customer::retrieve($customerId);

                return $customerId;
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Customer doesn't exist anymore - clear it and create new one
                $this->logger->warning('Stripe customer not found, creating new one', [
                    'user_id' => $user->getId(),
                    'old_customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);
                $customerId = null;
            }
        }

        // Create new Stripe customer, pre-filled with whatever tax-relevant
        // data the profile already has. Checkout's billing_address_collection
        // + customer_update will normalise and overwrite these during the
        // first paid checkout, so anything we send here is a best-effort seed.
        $customer = \Stripe\Customer::create($this->buildStripeCustomerPayload($user));

        // Save customer ID to paymentDetails JSON
        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        $this->logger->info('Stripe customer created', [
            'user_id' => $user->getId(),
            'stripe_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    /**
     * Build a Stripe Customer create-payload from the user profile.
     *
     * Populates `email`, `name`, and (only when the profile's country looks
     * like a 2-letter ISO 3166-1 alpha-2 code) an `address`. The regex is
     * intentionally loose — it catches structurally-plausible codes and lets
     * Stripe be the authoritative validator. Obviously-wrong free-text
     * country names ("Germany", "DEU") are skipped so they don't 400 the
     * Customer.create call. Anything we send here is a best-effort seed;
     * Checkout's required billing_address_collection overwrites it with the
     * normalised, user-confirmed address on the first paid checkout.
     *
     * @return array{
     *   email: string,
     *   name?: string,
     *   address?: array<string, string>,
     *   metadata: array{user_id: string},
     * }
     */
    private function buildStripeCustomerPayload(User $user): array
    {
        $payload = [
            'email' => $user->getMail(),
            'metadata' => [
                'user_id' => (string) $user->getId(),
            ],
        ];

        $details = $user->getUserDetails();

        $firstName = trim((string) ($details['firstName'] ?? $details['first_name'] ?? ''));
        $lastName = trim((string) ($details['lastName'] ?? $details['last_name'] ?? ''));
        $companyName = trim((string) ($details['companyName'] ?? ''));

        $personalName = trim($firstName.' '.$lastName);
        if ('' !== $personalName) {
            $payload['name'] = $personalName;
        } elseif ('' !== $companyName) {
            $payload['name'] = $companyName;
        }

        $country = trim((string) ($details['country'] ?? ''));
        if (1 === preg_match('/^[A-Za-z]{2}$/', $country)) {
            $address = ['country' => strtoupper($country)];
            $street = trim((string) ($details['street'] ?? ''));
            $zip = trim((string) ($details['zipCode'] ?? ''));
            $city = trim((string) ($details['city'] ?? ''));
            if ('' !== $street) {
                $address['line1'] = $street;
            }
            if ('' !== $zip) {
                $address['postal_code'] = $zip;
            }
            if ('' !== $city) {
                $address['city'] = $city;
            }
            $payload['address'] = $address;
        }

        return $payload;
    }
}
