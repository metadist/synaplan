<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing\PayFirst;

use App\Service\Billing\PayFirst\CheckoutSessionGateway;
use App\Service\Billing\PayFirst\PaidCheckout;
use Stripe\StripeObject;

/**
 * Stands in for Stripe: records every Checkout session the pay-first code
 * asks for and answers with a fake URL; answers lookups from what a test put
 * into {@see $sessions}, with a paid subscription at {@see $priceId}.
 */
final class RecordingGateway implements CheckoutSessionGateway
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var array<string, PaidCheckout> */
    public array $sessions = [];

    public string $priceId = 'price_pro';

    public int $subscriptionReads = 0;

    public function create(array $params): array
    {
        $this->created[] = $params;
        $n = count($this->created);

        return ['id' => 'cs_test_'.$n, 'url' => 'https://checkout.stripe.test/s/'.$n];
    }

    /** @var array<string, StripeObject> */
    public array $rawSessions = [];

    public function retrieve(string $sessionId, ?StripeObject &$loaded = null): ?PaidCheckout
    {
        $loaded = $this->rawSessions[$sessionId] ?? null;

        return $this->sessions[$sessionId] ?? null;
    }

    public function subscription(string $subscriptionId): StripeObject
    {
        ++$this->subscriptionReads;

        return StripeObject::constructFrom([
            'id' => $subscriptionId,
            'status' => 'active',
            'items' => ['data' => [[
                'price' => ['id' => $this->priceId],
                'current_period_start' => time(),
                'current_period_end' => time() + 30 * 86400,
            ]]],
            'latest_invoice' => ['id' => 'in_'.$subscriptionId, 'status' => 'paid', 'paid' => true],
        ]);
    }

    public function paidSession(string $sessionId, string $email, string $nonce = 'nonce', string $plan = 'PRO', bool $paid = true): PaidCheckout
    {
        return $this->sessions[$sessionId] = new PaidCheckout(
            $sessionId,
            $email,
            $plan,
            'cus_'.$sessionId,
            'sub_'.$sessionId,
            $paid,
            hash('sha256', $nonce),
        );
    }
}
