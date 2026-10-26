<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

/**
 * What a pay-first Checkout session says about the purchase: who paid, for
 * which plan, and whether the money is in.
 */
final readonly class PaidCheckout
{
    public function __construct(
        public string $sessionId,
        public string $email,
        public string $plan,
        public ?string $customerId,
        public ?string $subscriptionId,
        public bool $paid,
        public string $startNonceHash,
    ) {
    }

    /**
     * Paid, with the customer and subscription Stripe created at completion.
     */
    public function isSettled(): bool
    {
        return $this->paid && null !== $this->customerId && null !== $this->subscriptionId && '' !== $this->email;
    }
}
