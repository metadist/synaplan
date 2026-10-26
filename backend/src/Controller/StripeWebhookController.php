<?php

namespace App\Controller;

use App\Entity\Topup;
use App\Entity\User;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\StripeEntitlement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe Webhook Controller
 * Handles webhooks from Stripe for subscription events.
 *
 * Security:
 * - Webhook signature verification (HMAC)
 * - Idempotency check (prevents duplicate processing)
 * - Rate limiting (prevents DoS)
 * - A paid tier is granted only once the payment has settled
 *   ({@see StripeEntitlement}); `active` alone is not enough.
 */
#[Route('/api/v1/stripe')]
#[OA\Tag(name: 'Stripe')]
class StripeWebhookController extends AbstractController
{
    private const RATE_LIMIT_WINDOW = 60; // 1 minute
    private const RATE_LIMIT_MAX = 100; // max 100 webhooks per minute
    private const IDEMPOTENCY_TTL = 86400; // 24 hours

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private string $stripeWebhookSecret,
        private string $stripeSecretKey,
        private StripeEntitlement $entitlement,
        private TopupRepository $topupRepository,
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
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function handleWebhook(Request $request): JsonResponse
    {
        // Rate limiting check
        if (!$this->checkRateLimit($request)) {
            $this->logger->warning('Stripe webhook rate limit exceeded', [
                'ip' => $request->getClientIp(),
            ]);

            return $this->json(['error' => 'Rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            return $this->json(['error' => 'No signature'], Response::HTTP_BAD_REQUEST);
        }

        // Check if Stripe is configured
        if (empty($this->stripeWebhookSecret) || 'your_stripe_webhook_secret_here' === $this->stripeWebhookSecret) {
            $this->logger->error('Stripe webhook secret not configured');

            return $this->json(['error' => 'Webhook not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            // Verify webhook signature
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->stripeWebhookSecret
            );
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Client-side rejection: bad signature from a scanner/attacker, a
            // misconfigured third-party, or a Stripe-side secret rotation that
            // hasn't reached us yet. None of these warrant `error` (which is
            // reserved for actionable server-side faults per monolog.yaml).
            $this->logger->warning('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
                'ip' => $request->getClientIp(),
            ]);

            return $this->json(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Malformed payload from the caller — same reasoning as above:
            // it's a 400, not an internal error.
            $this->logger->warning('Stripe webhook invalid payload', [
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Idempotency check - prevent duplicate processing
        if ($this->isEventProcessed($event->id)) {
            $this->logger->info('Stripe webhook event already processed', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return $this->json(['success' => true, 'status' => 'already_processed']);
        }

        // Handle the event
        try {
            $handled = match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.created' => $this->handleSubscriptionCreated($event->data->object),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
                'customer.subscription.paused' => $this->handleSubscriptionPaused($event->data->object),
                'customer.subscription.resumed' => $this->handleSubscriptionResumed($event->data->object),
                'invoice.payment_succeeded' => $this->handlePaymentSucceeded($event->data->object),
                'invoice.payment_failed' => $this->handlePaymentFailed($event->data->object),
                default => false,
            };

            if (false === $handled) {
                $this->logger->info('Unhandled Stripe event', ['type' => $event->type]);
            }

            // Mark event as processed
            $this->markEventProcessed($event->id);

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process Stripe webhook', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't mark as processed on error - Stripe will retry
            return $this->json(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Rate limiting for webhook endpoint (fixed window per IP).
     *
     * The window start is stored alongside the counter because PSR-6
     * `save()` does not preserve a previously stored expiry: re-saving a
     * fetched item without `expiresAfter()` falls back to the pool default
     * (potentially "forever"), which used to make the counter immortal and
     * permanently 429 an IP once it ever crossed the limit.
     */
    private function checkRateLimit(Request $request): bool
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $cacheKey = 'stripe_webhook_rate_'.md5($ip);
        $now = time();

        $item = $this->cache->getItem($cacheKey);
        $state = $item->isHit() ? $item->get() : null;

        if (!is_array($state) || !isset($state['count'], $state['reset']) || $state['reset'] <= $now) {
            $state = ['count' => 0, 'reset' => $now + self::RATE_LIMIT_WINDOW];
        }

        if ($state['count'] >= self::RATE_LIMIT_MAX) {
            return false;
        }

        ++$state['count'];
        $item->set($state);
        $item->expiresAfter(max(1, $state['reset'] - $now));
        $this->cache->save($item);

        return true;
    }

    /**
     * Check if event was already processed (idempotency).
     */
    private function isEventProcessed(string $eventId): bool
    {
        $cacheKey = 'stripe_event_'.$eventId;
        $item = $this->cache->getItem($cacheKey);

        return $item->isHit();
    }

    /**
     * Mark event as processed.
     */
    private function markEventProcessed(string $eventId): void
    {
        $cacheKey = 'stripe_event_'.$eventId;
        $item = $this->cache->getItem($cacheKey);
        $item->set(time());
        $item->expiresAfter(self::IDEMPOTENCY_TTL);
        $this->cache->save($item);
    }

    private function handleCheckoutCompleted($session): bool
    {
        $this->logger->info('Checkout session completed', [
            'session_id' => $session->id,
            'customer' => $session->customer,
            'mode' => $session->mode ?? null,
        ]);

        // One-time budget top-ups (mode=payment) are credited to BUSER_TOPUPS.
        if ('topup' === ($session->metadata->type ?? null)) {
            return $this->handleTopupCompleted($session);
        }

        if ($this->isPayFirst($session)) {
            return true;
        }

        // Get user from client_reference_id (user ID set during checkout creation)
        $userId = $session->client_reference_id ?? null;
        $email = $session->customer_email ?? null;

        $user = null;
        if ($userId) {
            $user = $this->userRepository->find((int) $userId);
        }
        if (!$user && $email) {
            $user = $this->userRepository->findOneBy(['mail' => $email]);
        }

        if (!$user) {
            $this->logger->warning('User not found for checkout', [
                'user_id' => $userId,
                'email' => $email,
            ]);

            return false;
        }

        // Update user's Stripe customer ID in paymentDetails JSON
        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['stripe_customer_id'] = $session->customer;
        $paymentDetails['stripe_session_id'] = $session->id;
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();

        return true;
    }

    /**
     * Credit a one-time budget top-up. Idempotent on the Stripe session id
     * (DB unique index) so webhook retries never double-credit.
     */
    private function handleTopupCompleted(StripeObject $session): bool
    {
        $userId = $session->client_reference_id ?? ($session->metadata->user_id ?? null);
        $user = $userId ? $this->userRepository->find((int) $userId) : null;

        if (!$user) {
            // Deterministic failure: the user referenced by the session does
            // not exist and never will. Returning `true` ACKs the event so
            // Stripe stops retrying a webhook that can never succeed (it would
            // otherwise be redelivered until Stripe's retry budget is spent).
            $this->logger->warning('User not found for top-up checkout, acknowledging to stop Stripe retries', [
                'session_id' => $session->id,
                'user_id' => $userId,
            ]);

            return true;
        }

        // Durable idempotency guard (in addition to the event-level cache check).
        if ($this->topupRepository->existsForSession($session->id)) {
            $this->logger->info('Top-up already credited for session', [
                'session_id' => $session->id,
                'user_id' => $user->getId(),
            ]);

            return true;
        }

        // Net EUR the user bought (excludes any Stripe Tax). Prefer the value we
        // stamped at checkout; fall back to the pre-tax subtotal.
        $amountEur = isset($session->metadata->topup_eur)
            ? (float) $session->metadata->topup_eur
            : ((int) ($session->amount_subtotal ?? 0)) / 100.0;

        if ($amountEur <= 0) {
            // Also deterministic — a zero/negative amount cannot be credited.
            // ACK so Stripe does not redeliver forever (see above).
            $this->logger->warning('Top-up completed with non-positive amount, acknowledging to stop Stripe retries', [
                'session_id' => $session->id,
                'user_id' => $user->getId(),
            ]);

            return true;
        }

        $topup = new Topup();
        $topup->setUserId((int) $user->getId())
            ->setAmount(number_format($amountEur, 2, '.', ''))
            ->setCurrency('EUR')
            ->setStripeSessionId($session->id)
            ->setStatus('completed')
            ->setCreated(time());

        // Persist the customer id too (first purchase may set it).
        if (!empty($session->customer)) {
            $paymentDetails = $user->getPaymentDetails();
            $paymentDetails['stripe_customer_id'] = $session->customer;
            $user->setPaymentDetails($paymentDetails);
        }

        try {
            $this->topupRepository->save($topup, false);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The existsForSession() check above and this flush() are not
            // atomic: a concurrent webhook retry for the same session can pass
            // the check and then collide on the BUSER_TOPUPS unique index here.
            // That collision proves the credit already landed, so treat it as a
            // successful idempotent no-op instead of bubbling a 500.
            $this->logger->info('Top-up credit raced a concurrent retry; already credited', [
                'session_id' => $session->id,
                'user_id' => $user->getId(),
            ]);

            return true;
        }

        $this->logger->info('Budget top-up credited', [
            'session_id' => $session->id,
            'user_id' => $user->getId(),
            'amount_eur' => $amountEur,
        ]);

        return true;
    }

    private function handleSubscriptionCreated($subscription): bool
    {
        $this->logger->info('Subscription created', [
            'subscription_id' => $subscription->id,
            'customer' => $subscription->customer,
        ]);

        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return false;
        }

        // Cancel any other active subscriptions for this customer (upgrade logic)
        $this->cancelOtherSubscriptions($subscription->customer, $subscription->id);

        // Record the subscription; the tier follows only once the first
        // invoice is paid. A card settles within this same event, a SEPA
        // mandate does not — its grant arrives with invoice.payment_succeeded.
        $settled = $this->entitlement->apply($user, $subscription, $this->entitlement->latestInvoice($subscription));

        $this->em->flush();

        $this->logger->info($settled ? 'User subscription activated' : 'User subscription recorded, payment pending', [
            'user_id' => $user->getId(),
            'level' => $user->getUserLevel(),
        ]);

        return true;
    }

    /**
     * Cancel all other active subscriptions for a customer (used during upgrade).
     */
    private function cancelOtherSubscriptions(string $customerId, string $newSubscriptionId): void
    {
        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            // Get all subscriptions for this customer
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $customerId,
                'status' => 'active',
            ]);

            foreach ($subscriptions->data as $existingSubscription) {
                // Skip the new subscription
                if ($existingSubscription->id === $newSubscriptionId) {
                    continue;
                }

                // Cancel the old subscription immediately
                $existingSubscription->cancel();

                $this->logger->info('Canceled old subscription during upgrade', [
                    'canceled_subscription_id' => $existingSubscription->id,
                    'new_subscription_id' => $newSubscriptionId,
                    'customer' => $customerId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel old subscriptions', [
                'customer' => $customerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleSubscriptionUpdated($subscription): bool
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return false;
        }

        $paymentDetails = $user->getPaymentDetails();
        $currentSubscriptionId = $paymentDetails['subscription']['stripe_subscription_id'] ?? null;

        // Only process updates for the current subscription
        if ($currentSubscriptionId && $currentSubscriptionId !== $subscription->id) {
            $this->logger->info('Ignoring subscription update for old subscription', [
                'user_id' => $user->getId(),
                'updated_subscription_id' => $subscription->id,
                'current_subscription_id' => $currentSubscriptionId,
            ]);

            return true;
        }

        // Plan, period and tier. The tier only moves when the invoice behind
        // this update is paid — a plan change on a mandate that never settled
        // stays at the current level.
        $this->entitlement->apply($user, $subscription, $this->entitlement->latestInvoice($subscription));
        $paymentDetails = $user->getPaymentDetails();

        // Clear payment_failed when Stripe's smart-retry resolves the
        // past_due invoice via subscription.updated rather than via the
        // invoice.payment_succeeded path (issue #856 recovery criterion).
        // Both `active` and `trialing` count as a healthy subscription.
        if (in_array($subscription->status, ['active', 'trialing'], true)) {
            unset(
                $paymentDetails['subscription']['payment_failed'],
                $paymentDetails['subscription']['payment_failed_at']
            );
        }

        // Track cancellation at period end (user canceled but still has access until period ends)
        $paymentDetails['subscription']['cancel_at_period_end'] = $subscription->cancel_at_period_end ?? false;
        if ($subscription->cancel_at_period_end) {
            $paymentDetails['subscription']['cancel_at'] = $subscription->cancel_at;
            $this->logger->info('User subscription scheduled for cancellation', [
                'user_id' => $user->getId(),
                'cancel_at' => $subscription->cancel_at,
            ]);
        } else {
            // Remove cancellation info if subscription was reactivated
            unset($paymentDetails['subscription']['cancel_at']);
        }

        $user->setPaymentDetails($paymentDetails);
        $this->em->flush();

        return true;
    }

    private function handleSubscriptionDeleted($subscription): bool
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return false;
        }

        $paymentDetails = $user->getPaymentDetails();
        $currentSubscriptionId = $paymentDetails['subscription']['stripe_subscription_id'] ?? null;

        // Only downgrade if this is the CURRENT active subscription being deleted
        // This prevents race conditions where old subscription deletions reset the level
        if ($currentSubscriptionId === $subscription->id) {
            // Downgrade to NEW
            $user->setUserLevel('NEW');

            $paymentDetails['subscription']['status'] = 'canceled';
            $paymentDetails['subscription']['canceled_at'] = time();
            $user->setPaymentDetails($paymentDetails);

            $this->em->flush();

            $this->logger->info('User subscription canceled', [
                'user_id' => $user->getId(),
                'subscription_id' => $subscription->id,
            ]);
        } else {
            $this->logger->info('Ignoring subscription deletion for old subscription', [
                'user_id' => $user->getId(),
                'deleted_subscription_id' => $subscription->id,
                'current_subscription_id' => $currentSubscriptionId,
            ]);
        }

        return true;
    }

    private function handleSubscriptionPaused($subscription): bool
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return false;
        }

        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = 'paused';
        $paymentDetails['subscription']['paused_at'] = time();
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();

        $this->logger->info('User subscription paused', [
            'user_id' => $user->getId(),
        ]);

        return true;
    }

    private function handleSubscriptionResumed($subscription): bool
    {
        $user = $this->getUserByStripeCustomer($subscription->customer);
        if (!$user) {
            return false;
        }

        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['status'] = $subscription->status;
        unset($paymentDetails['subscription']['paused_at']);
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();

        $this->logger->info('User subscription resumed', [
            'user_id' => $user->getId(),
        ]);

        return true;
    }

    private function handlePaymentSucceeded($invoice): bool
    {
        $this->logger->info('Payment succeeded', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount_paid,
            'customer' => $invoice->customer,
        ]);

        $user = $this->getUserByStripeCustomer($invoice->customer);
        if (!$user) {
            return true;
        }

        // Money has settled: this is the event that grants the tier for
        // delayed-notification methods (SEPA, Bacs, ACH) and re-affirms it on
        // every renewal. Only subscriptions this account currently holds (or
        // has none of yet) are applied; a stale subscription's invoice must
        // not revive an old plan.
        $invoiceSubscriptionId = $this->entitlement->subscriptionIdOfInvoice($invoice);
        $currentId = $user->getPaymentDetails()['subscription']['stripe_subscription_id'] ?? null;
        if (null !== $invoiceSubscriptionId && (null === $currentId || $currentId === $invoiceSubscriptionId)) {
            $subscription = $this->entitlement->subscriptionOfInvoice($invoice);
            $this->entitlement->apply($user, $subscription, $this->entitlement->proofInvoice($subscription, $invoice));
        }

        // Clear the payment-failed flag so the SubscriptionView warning
        // disappears on the next reload (issue #856 recovery criterion).
        $paymentDetails = $user->getPaymentDetails();
        if (isset($paymentDetails['subscription']['payment_failed']) || isset($paymentDetails['subscription']['payment_failed_at'])) {
            unset($paymentDetails['subscription']['payment_failed'], $paymentDetails['subscription']['payment_failed_at']);
            $user->setPaymentDetails($paymentDetails);
            $this->logger->info('Cleared payment_failed flag after invoice.payment_succeeded', [
                'user_id' => $user->getId(),
                'invoice_id' => $invoice->id,
            ]);
        }

        $this->em->flush();

        return true;
    }

    private function handlePaymentFailed($invoice): bool
    {
        $user = $this->getUserByStripeCustomer($invoice->customer);
        if (!$user) {
            return false;
        }

        $this->logger->warning('Payment failed', [
            'user_id' => $user->getId(),
            'invoice_id' => $invoice->id,
        ]);

        // Update subscription status
        $paymentDetails = $user->getPaymentDetails();
        $paymentDetails['subscription']['payment_failed'] = true;
        $paymentDetails['subscription']['payment_failed_at'] = time();
        $user->setPaymentDetails($paymentDetails);

        $this->em->flush();

        return true;
    }

    /**
     * Find user by Stripe customer ID (searches in paymentDetails JSON).
     */
    /**
     * Pay-first sessions have no account yet; the account is created from the
     * paid session (PF2). They are never attached by email to an existing
     * account here.
     */
    private function isPayFirst(StripeObject $session): bool
    {
        if (PayFirstCheckout::FLOW !== ($session->metadata->flow ?? null)) {
            return false;
        }
        $this->logger->info('Pay-first checkout completed; account creation is not enabled yet', ['session_id' => $session->id]);

        return true;
    }

    private function getUserByStripeCustomer(string $customerId): ?User
    {
        return $this->userRepository->findByStripeCustomerId($customerId);
    }
}
