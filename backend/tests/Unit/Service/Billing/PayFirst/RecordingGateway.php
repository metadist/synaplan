<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing\PayFirst;

use App\Service\Billing\PayFirst\CheckoutSessionGateway;

/**
 * Stands in for Stripe: records every Checkout session the pay-first code
 * asks for and answers with a fake URL.
 */
final class RecordingGateway implements CheckoutSessionGateway
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    public function create(array $params): array
    {
        $this->created[] = $params;
        $n = count($this->created);

        return ['id' => 'cs_test_'.$n, 'url' => 'https://checkout.stripe.test/s/'.$n];
    }
}
