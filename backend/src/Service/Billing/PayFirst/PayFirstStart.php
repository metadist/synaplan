<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

/**
 * A started pay-first checkout: where to send the browser, and the nonce the
 * browser keeps in its HttpOnly cookie (only its hash is in the session).
 */
final readonly class PayFirstStart
{
    public function __construct(
        public string $checkoutUrl,
        public string $nonce,
    ) {
    }
}
