<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use OpenApi\Attributes as OA;

/**
 * Subscription Management Controller
 * Handles subscription plans and Stripe checkout
 */
#[Route('/api/v1/subscription')]
#[OA\Tag(name: 'Subscription')]
class SubscriptionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $stripeSecretKey,
        private string $stripePricePro,
        private string $stripePriceTeam,
        private string $stripePriceBusiness,
        private string $frontendUrl
    ) {}

    /**
     * Check if Stripe is properly configured
     */
    private function isStripeConfigured(): bool
    {
        return !empty($this->stripeSecretKey) 
            && $this->stripeSecretKey !== 'your_stripe_secret_key_here'
            && !empty($this->stripePricePro)
            && $this->stripePricePro !== 'price_xxx';
    }

    /**
     * Get available subscription plans
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
                'stripePriceId' => $this->stripePricePro,
                'price' => 19.99,
                'currency' => 'EUR',
                'interval' => 'month',
                'features' => [
                    'Unlimited Messages',
                    '100 Images/Month',
                    'Advanced AI Models',
                    'Priority Support',
                    '10GB Storage',
                ]
            ],
            [
                'id' => 'TEAM',
                'name' => 'Team',
                'stripePriceId' => $this->stripePriceTeam,
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
                ]
            ],
            [
                'id' => 'BUSINESS',
                'name' => 'Business',
                'stripePriceId' => $this->stripePriceBusiness,
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
                ]
            ],
        ];

        return $this->json([
            'plans' => $plans,
            'stripeConfigured' => $this->isStripeConfigured()
        ]);
    }

    /**
     * Create Stripe checkout session
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
                new OA\Property(property: 'planId', type: 'string', enum: ['PRO', 'TEAM', 'BUSINESS'])
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
    #[OA\Response(response: 503, description: 'Stripe not configured')]
    public function createCheckoutSession(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if Stripe is configured
        if (!$this->isStripeConfigured()) {
            $this->logger->warning('Stripe checkout attempted but Stripe is not configured');
            return $this->json([
                'error' => 'Subscription service is currently unavailable. Please contact support.',
                'code' => 'STRIPE_NOT_CONFIGURED'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = json_decode($request->getContent(), true);
        $planId = $data['planId'] ?? null;

        $priceId = match($planId) {
            'PRO' => $this->stripePricePro,
            'TEAM' => $this->stripePriceTeam,
            'BUSINESS' => $this->stripePriceBusiness,
            default => null
        };

        if (!$priceId) {
            return $this->json(['error' => 'Invalid plan'], Response::HTTP_BAD_REQUEST);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Create or get Stripe customer
            $customerId = $this->getOrCreateStripeCustomer($user);

            // Create checkout session
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
                'client_reference_id' => (string)$user->getId(),
                'metadata' => [
                    'user_id' => $user->getId(),
                    'plan' => $planId,
                ],
            ]);

            $this->logger->info('Stripe checkout session created', [
                'user_id' => $user->getId(),
                'session_id' => $session->id,
                'plan' => $planId
            ]);

            return $this->json([
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create checkout session', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return $this->json(['error' => 'Failed to create checkout session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get current subscription status
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
        #[CurrentUser] ?User $user
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
        ]);
    }

    /**
     * Create Stripe Customer Portal session
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
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isStripeConfigured()) {
            return $this->json([
                'error' => 'Subscription service is currently unavailable.',
                'code' => 'STRIPE_NOT_CONFIGURED'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            $paymentDetails = $user->getPaymentDetails();
            $customerId = $paymentDetails['stripe_customer_id'] ?? null;

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
                'session_id' => $session->id
            ]);

            return $this->json([
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create portal session', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return $this->json(['error' => 'Failed to create portal session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get or create Stripe customer for user
     */
    private function getOrCreateStripeCustomer(User $user): string
    {
        $paymentDetails = $user->getPaymentDetails();
        $customerId = $paymentDetails['stripe_customer_id'] ?? null;

        if ($customerId) {
            return $customerId;
        }

        // Create new Stripe customer
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);
        $customer = \Stripe\Customer::create([
            'email' => $user->getMail(),
            'metadata' => [
                'user_id' => $user->getId(),
            ],
        ]);

        // Save customer ID
        $paymentDetails['stripe_customer_id'] = $customer->id;
        $user->setPaymentDetails($paymentDetails);
        $this->em->flush();

        return $customer->id;
    }

    private function handleSubscriptionCreated($subscription): void
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) return;

        $priceId = $subscription->items->data[0]->price->id ?? null;
        $userLevel = match($priceId) {
            $this->stripePricePro => 'PRO',
            $this->stripePriceTeam => 'TEAM',
            $this->stripePriceBusiness => 'BUSINESS',
            default => 'NEW'
        };

        $user->setUserLevel($userLevel);
        
        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription'] = [
            'stripe_subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'subscription_start' => $subscription->current_period_start,
            'subscription_end' => $subscription->current_period_end,
            'plan' => $userLevel,
        ];
        $user->setPaymentDetails($paymentDetails);
        
        $this->em->flush();
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) return;

        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = $subscription->status;
        $paymentDetails['subscription']['subscription_end'] = $subscription->current_period_end;
        $user->setPaymentDetails($paymentDetails);
        
        $this->em->flush();
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) return;

        $user->setUserLevel('NEW');
        
        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = 'canceled';
        $user->setPaymentDetails($paymentDetails);
        
        $this->em->flush();
    }

    private function handlePaymentSucceeded($invoice): void
    {
        $this->logger->info('Payment succeeded', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount_paid
        ]);
    }

    private function handlePaymentFailed($invoice): void
    {
        $user = $this->getUserByStripeCustomer($invoice->customer);
        if (!$user) return;

        $this->logger->warning('Payment failed', [
            'user_id' => $user->getId(),
            'invoice_id' => $invoice->id
        ]);
    }

    private function getUserByStripeCustomer(string $customerId): ?User
    {
        $userRepo = $this->em->getRepository(User::class);
        $users = $userRepo->findAll();

        foreach ($users as $user) {
            $details = $user->getPaymentDetails();
            if (isset($details['stripe_customer_id']) && $details['stripe_customer_id'] === $customerId) {
                return $user;
            }
        }

        return null;
    }
}

