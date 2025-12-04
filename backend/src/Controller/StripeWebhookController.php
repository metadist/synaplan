<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe Webhook Controller
 * Handles webhooks from Stripe for subscription events.
 */
#[Route('/api/v1/stripe')]
#[OA\Tag(name: 'Stripe')]
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $stripeWebhookSecret,
    ) {
    }

    /**
     * Handle Stripe webhook events.
     */
    #[Route('/webhook', name: 'stripe_webhook', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/stripe/webhook',
        summary: 'Handle Stripe webhooks',
        tags: ['Stripe']
    )]
    #[OA\Response(response: 200, description: 'Webhook processed')]
    #[OA\Response(response: 400, description: 'Invalid signature')]
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            return $this->json(['error' => 'No signature'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Verify webhook signature
            \Stripe\Stripe::setApiKey($this->getParameter('stripe_secret_key'));
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->stripeWebhookSecret
            );
        } catch (\Exception $e) {
            $this->logger->error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        // Handle the event
        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.created' => $this->handleSubscriptionCreated($event->data->object),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
                'invoice.payment_succeeded' => $this->handlePaymentSucceeded($event->data->object),
                'invoice.payment_failed' => $this->handlePaymentFailed($event->data->object),
                default => $this->logger->info('Unhandled Stripe event', ['type' => $event->type]),
            };

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process Stripe webhook', [
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function handleCheckoutCompleted($session): void
    {
        $this->logger->info('Checkout session completed', [
            'session_id' => $session->id,
            'customer' => $session->customer,
        ]);

        $email = $session->customer_email;
        if (!$email) {
            $this->logger->warning('No email in checkout session');

            return;
        }

        $user = $this->userRepository->findOneBy(['mail' => $email]);
        if (!$user) {
            $this->logger->warning('User not found for checkout', ['email' => $email]);

            return;
        }

        // Update user's payment details
        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['stripe_customer_id'] = $session->customer;
        $paymentDetails['stripe_session_id'] = $session->id;
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();
    }

    private function handleSubscriptionCreated($subscription): void
    {
        $this->logger->info('Subscription created', [
            'subscription_id' => $subscription->id,
            'customer' => $subscription->customer,
        ]);

        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return;
        }

        // Map Stripe price to user level
        $priceId = $subscription->items->data[0]->price->id ?? null;
        $userLevel = $this->mapPriceIdToLevel($priceId);

        // Update user
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

        $this->logger->info('User subscription activated', [
            'user_id' => $user->getId(),
            'level' => $userLevel,
        ]);
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return;
        }

        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = $subscription->status;
        $paymentDetails['subscription']['subscription_end'] = $subscription->current_period_end;
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return;
        }

        // Downgrade to NEW
        $user->setUserLevel('NEW');

        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = 'canceled';
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();

        $this->logger->info('User subscription canceled', [
            'user_id' => $user->getId(),
        ]);
    }

    private function handlePaymentSucceeded($invoice): void
    {
        $this->logger->info('Payment succeeded', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount_paid,
        ]);
    }

    private function handlePaymentFailed($invoice): void
    {
        $user = $this->getUserByStripeCustomer($invoice->customer);
        if (!$user) {
            return;
        }

        $this->logger->warning('Payment failed', [
            'user_id' => $user->getId(),
            'invoice_id' => $invoice->id,
        ]);
    }

    private function getUserByStripeCustomer(string $customerId): ?User
    {
        // Search in paymentDetails JSON for stripe_customer_id
        $qb = $this->userRepository->createQueryBuilder('u');
        $users = $qb->getQuery()->getResult();

        foreach ($users as $user) {
            $details = $user->getPaymentDetails();
            if (isset($details['stripe_customer_id']) && $details['stripe_customer_id'] === $customerId) {
                return $user;
            }
            if (isset($details['subscription']['stripe_customer_id']) && $details['subscription']['stripe_customer_id'] === $customerId) {
                return $user;
            }
        }

        return null;
    }

    private function mapPriceIdToLevel(?string $priceId): string
    {
        // Map Stripe price IDs to user levels
        // These should be configured in your .env
        $priceMapping = [
            $this->getParameter('stripe_price_pro') => 'PRO',
            $this->getParameter('stripe_price_team') => 'TEAM',
            $this->getParameter('stripe_price_business') => 'BUSINESS',
        ];

        return $priceMapping[$priceId] ?? 'NEW';
    }
}
