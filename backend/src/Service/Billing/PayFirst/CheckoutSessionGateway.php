<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

/**
 * Creates a Stripe Checkout session. A seam so the pay-first rules are tested
 * without calling Stripe.
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
}
