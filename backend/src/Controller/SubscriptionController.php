<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    /**
     * Check if Stripe is properly configured.
     */
    private function isStripeConfigured(): bool
    {
        return !empty($this->stripeSecretKey)
            && 'your_stripe_secret_key_here' !== $this->stripeSecretKey
            && !empty($this->stripePricePro)
            && 'price_xxx' !== $this->stripePricePro
            && 'price_pro' !== $this->stripePricePro;
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
                'stripePriceId' => $this->isStripeConfigured() ? $this->stripePricePro : null,
                'price' => 19.99,
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
                'stripePriceId' => $this->isStripeConfigured() ? $this->stripePriceTeam : null,
                'price' => 49.99,
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
                'stripePriceId' => $this->isStripeConfigured() ? $this->stripePriceBusiness : null,
                'price' => 99.99,
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
            'stripeConfigured' => $this->isStripeConfigured(),
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
        if (!$this->isStripeConfigured()) {
            $this->logger->warning('Stripe checkout attempted but Stripe is not configured');

            return $this->json([
                'error' => 'Subscription service is currently unavailable. Please contact support.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
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

            // Create checkout session
            // Note: Can't use both 'customer' and 'customer_email' - Stripe only allows one
            $session = \Stripe\Checkout\Session::create([
                'customer' => $customerId,
                'payment_method_types' => ['card', 'sepa_debit'],
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'success_url' => $this->frontendUrl . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->frontendUrl . '/subscription/cancel',
                'client_reference_id' => (string) $user->getId(),
                'metadata' => [
                    'user_id' => $user->getId(),
                    'plan' => $planId,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => $user->getId(),
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
                new OA\Property(property: 'plan', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'nextBilling', type: 'string'),
                new OA\Property(property: 'cancelAt', type: 'string'),
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
                'plan' => $user->getUserLevel(),
            ]);
        }

        return $this->json([
            'hasSubscription' => true,
            'plan' => $user->getUserLevel(),
            'status' => $subscription['status'] ?? 'unknown',
            'nextBilling' => $subscription['subscription_end'] ?? null,
            'cancelAt' => $subscription['cancel_at'] ?? null,
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

        if (!$this->isStripeConfigured()) {
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
            $paymentDetails['subscription'] = [
                'stripe_subscription_id' => $activeSubscription->id,
                'status' => $activeSubscription->status,
                'subscription_start' => $activeSubscription->current_period_start,
                'subscription_end' => $activeSubscription->current_period_end,
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
                'error' => 'Failed to sync from Stripe: ' . $e->getMessage(),
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

        if (!$this->isStripeConfigured()) {
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
                'return_url' => $this->frontendUrl . '/subscription',
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

        if (!$this->isStripeConfigured()) {
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
        $cacheKey = 'checkout_rate_' . $user->getId();

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

        // Create new Stripe customer
        $customer = \Stripe\Customer::create([
            'email' => $user->getMail(),
            'metadata' => [
                'user_id' => $user->getId(),
            ],
        ]);

        // Save customer ID to paymentDetails JSON
        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        $this->logger->info('Stripe customer created', [
            'user_id' => $user->getId(),
            'stripe_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }
}
