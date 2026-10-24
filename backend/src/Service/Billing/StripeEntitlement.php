<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\User;
use App\Service\Auth\SignupAnomalyAlert;
use Psr\Log\LoggerInterface;
use Stripe\Invoice;
use Stripe\StripeObject;
use Stripe\Subscription;

/**
 * Decides when a Stripe subscription entitles an account to a paid tier.
 *
 * The tier is granted only once money has settled: the subscription is
 * `active` AND its latest invoice is `paid`. Stripe reports a subscription as
 * `active` the moment a mandate is accepted, which for delayed-notification
 * methods (SEPA Direct Debit, Bacs, ACH) is 1–14 working days before the money
 * arrives — and before it can bounce. On 2026-10-24 that window was used to
 * open several hundred paid accounts on fake IBANs within minutes.
 *
 * Every write of a Stripe-granted tier goes through {@see apply()}: the
 * webhook handlers (subscription.created / updated, invoice.payment_succeeded)
 * and the on-demand sync endpoint. Downgrades (subscription.deleted, no active
 * subscription in Stripe) stay with their callers; this class never lowers a
 * tier and never touches an ADMIN account.
 */
final readonly class StripeEntitlement
{
    public const LEVEL_FREE = 'NEW';

    /** Paid tiers, lowest first. */
    public const PAID_LEVELS = ['PRO', 'TEAM', 'BUSINESS'];

    public function __construct(
        private SignupAnomalyAlert $anomalyAlert,
        private LoggerInterface $logger,
        private string $stripePricePro,
        private string $stripePriceTeam,
        private string $stripePriceBusiness,
    ) {
    }

    /** @return array<string, string> level ⇒ Stripe price id */
    public function prices(): array
    {
        return [
            'PRO' => $this->stripePricePro,
            'TEAM' => $this->stripePriceTeam,
            'BUSINESS' => $this->stripePriceBusiness,
        ];
    }

    public function levelForPrice(?string $priceId): string
    {
        if (null === $priceId || '' === $priceId) {
            return self::LEVEL_FREE;
        }

        foreach ($this->prices() as $level => $configuredPrice) {
            if ('' !== $configuredPrice && $configuredPrice === $priceId) {
                return $level;
            }
        }

        return self::LEVEL_FREE;
    }

    /**
     * Has the money for this subscription actually arrived?
     *
     * A trial is the operator's explicit decision to grant access before
     * payment, so it counts. Everything else needs an `active` subscription
     * whose latest invoice Stripe marks as paid — an `open` invoice with a
     * `processing` payment (the SEPA case) does not.
     */
    public function isSettled(StripeObject $subscription, ?StripeObject $invoice): bool
    {
        $status = (string) ($subscription->status ?? '');
        if ('trialing' === $status) {
            return true;
        }
        if ('active' !== $status || null === $invoice) {
            return false;
        }

        return 'paid' === (string) ($invoice->status ?? '') || true === ($invoice->paid ?? false);
    }

    /**
     * The subscription's latest invoice, fetched from Stripe unless the event
     * already carried it expanded. Null when there is none or Stripe cannot be
     * reached — which reads as "not settled", so an outage can only delay a
     * grant, never produce one.
     */
    public function latestInvoice(StripeObject $subscription): ?StripeObject
    {
        $latest = $subscription->latest_invoice ?? null;
        if ($latest instanceof StripeObject) {
            return $latest;
        }
        if (!is_string($latest) || '' === $latest) {
            return null;
        }

        try {
            return Invoice::retrieve($latest);
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe entitlement: latest invoice could not be fetched, treating as unsettled', [
                'subscription_id' => $subscription->id ?? null,
                'invoice_id' => $latest,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The highest-tier subscription whose payment has settled, with the
     * invoice that proves it. Null when none of them has.
     *
     * @param iterable<StripeObject> $subscriptions
     *
     * @return array{0: StripeObject, 1: ?StripeObject}|null
     */
    public function highestSettled(iterable $subscriptions): ?array
    {
        $best = null;
        $bestRank = -1;
        foreach ($subscriptions as $subscription) {
            $invoice = $this->latestInvoice($subscription);
            if (!$this->isSettled($subscription, $invoice)) {
                continue;
            }
            $firstItem = $subscription->items->data[0] ?? null;
            $level = $this->levelForPrice($firstItem instanceof StripeObject ? ($firstItem->price->id ?? null) : null);
            $rank = array_search($level, self::PAID_LEVELS, true);
            if (false !== $rank && $rank > $bestRank) {
                $bestRank = $rank;
                $best = [$subscription, $invoice];
            }
        }

        return $best;
    }

    /**
     * The id of the subscription an invoice belongs to, or null for one-off
     * invoices — without an API call, so callers can decide locally whether
     * the invoice concerns the account's current subscription. Handles both
     * the classic `invoice.subscription` field and the
     * `invoice.parent.subscription_details.subscription` shape of newer
     * Stripe API versions.
     */
    public function subscriptionIdOfInvoice(StripeObject $invoice): ?string
    {
        $ref = $invoice->subscription ?? null;
        if (null === $ref) {
            $parent = $invoice->parent ?? null;
            $ref = $parent instanceof StripeObject ? ($parent->subscription_details->subscription ?? null) : null;
        }
        if ($ref instanceof StripeObject) {
            $ref = $ref->id ?? null;
        }

        return is_string($ref) && '' !== $ref ? $ref : null;
    }

    /** The subscription an invoice belongs to, or null for one-off invoices. */
    public function subscriptionOfInvoice(StripeObject $invoice): ?StripeObject
    {
        $ref = $invoice->subscription ?? null;
        if (null === $ref) {
            $parent = $invoice->parent ?? null;
            $ref = $parent instanceof StripeObject ? ($parent->subscription_details->subscription ?? null) : null;
        }
        if ($ref instanceof StripeObject) {
            return $ref;
        }
        if (!is_string($ref) || '' === $ref) {
            return null;
        }

        try {
            return Subscription::retrieve($ref);
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe entitlement: subscription of invoice could not be fetched', [
                'invoice_id' => $invoice->id ?? null,
                'subscription_id' => $ref,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record the subscription on the account and grant the tier when settled.
     *
     * Returns whether the tier is in force. The caller flushes.
     */
    public function apply(User $user, StripeObject $subscription, ?StripeObject $invoice): bool
    {
        $firstItem = $subscription->items->data[0] ?? null;
        $level = $this->levelForPrice($firstItem instanceof StripeObject ? ($firstItem->price->id ?? null) : null);
        $settled = $this->isSettled($subscription, $invoice);

        $details = $user->getPaymentDetails();
        $previous = is_array($details['subscription'] ?? null) ? $details['subscription'] : [];
        $sameSubscription = ($previous['stripe_subscription_id'] ?? null) === ($subscription->id ?? null);
        $details['subscription'] = array_merge($sameSubscription ? $previous : [], [
            'source' => BillingService::SOURCE_STRIPE,
            'stripe_subscription_id' => $subscription->id,
            'status' => $subscription->status ?? null,
            'subscription_start' => $firstItem instanceof StripeObject ? ($firstItem['current_period_start'] ?? null) : null,
            'subscription_end' => $firstItem instanceof StripeObject ? ($firstItem['current_period_end'] ?? null) : null,
            'plan' => $level,
            'settled' => $settled,
        ]);
        $user->setPaymentDetails($details);

        if (!$settled || !in_array($level, self::PAID_LEVELS, true)) {
            $this->logger->info('Stripe entitlement withheld: payment not settled', [
                'user_id' => $user->getId(),
                'subscription_id' => $subscription->id ?? null,
                'subscription_status' => $subscription->status ?? null,
                'invoice_status' => null !== $invoice ? ($invoice->status ?? null) : null,
                'plan' => $level,
            ]);

            return false;
        }

        if ($user->isAdmin() || $user->getUserLevel() === $level) {
            return true;
        }

        $user->setUserLevel($level);
        $this->logger->info('Stripe entitlement granted', [
            'user_id' => $user->getId(),
            'subscription_id' => $subscription->id ?? null,
            'level' => $level,
        ]);
        $this->anomalyAlert->paidTierGranted($user, $level);

        return true;
    }
}
