<?php

declare(strict_types=1);

namespace App\Service\Iap;

/**
 * MOBILE-APP SEAM (Epic 5.4): the normalized lifecycle state of an IAP
 * subscription, unified across Apple and Google so the rest of the app never
 * touches store-specific status codes.
 */
enum IapEntitlementState: string
{
    /** Paid and current. */
    case ACTIVE = 'active';
    /** Payment failed but the user keeps access during the store grace window. */
    case GRACE_PERIOD = 'grace_period';
    /** Billing retry without grace — access is suspended until recovery. */
    case ON_HOLD = 'on_hold';
    /** Lapsed / not renewed. */
    case EXPIRED = 'expired';
    /** Refunded or revoked by the store — access must be removed. */
    case REVOKED = 'revoked';
    /** Purchase not yet completed (e.g. Google deferred/SCA). Never grants. */
    case PENDING = 'pending';

    /**
     * Whether this state should grant the paid tier. Only ACTIVE and
     * GRACE_PERIOD do — every other state means "no entitlement right now".
     */
    public function grantsAccess(): bool
    {
        return self::ACTIVE === $this || self::GRACE_PERIOD === $this;
    }
}
