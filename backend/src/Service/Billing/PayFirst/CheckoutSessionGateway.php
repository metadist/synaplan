<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use Stripe\StripeObject;

/**
 * Talks to Stripe Checkout for the pay-first flow. A seam so the pay-first
 * rules are tested without calling Stripe.
 */
interface CheckoutSessionGateway
{
    /**
     * @param array<string, mixed> $params Stripe `checkout.sessions.create` parameters
     *
     * @return array{id: string, url: string}
     *
     * @throws PayFirstException when Stripe refuses or cannot be reached
     */
    public function create(array $params): array;

    /**
     * The pay-first Checkout session as Stripe reports it now, or null when it
     * does not exist or was not started by the pay-first flow.
     *
     * @throws PayFirstException when Stripe cannot be reached
     */
    public function retrieve(string $sessionId): ?PaidCheckout;

    /**
     * The subscription a paid session created, with its latest invoice, for
     * {@see \App\Service\Billing\StripeEntitlement::apply()}.
     *
     * @throws PayFirstException when Stripe cannot be reached
     */
    public function subscription(string $subscriptionId): StripeObject;
}
