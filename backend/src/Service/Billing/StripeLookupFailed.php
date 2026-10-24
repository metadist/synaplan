<?php

declare(strict_types=1);

namespace App\Service\Billing;

/**
 * Stripe could not be asked whether a payment settled.
 *
 * Webhook handlers let this escape so the controller answers 500 and Stripe
 * retries. Returning "not settled" instead would acknowledge the event and
 * leave a paying customer on the free tier until someone syncs by hand.
 */
final class StripeLookupFailed extends \RuntimeException
{
}
