<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * What the operator sees for a single model on the status page.
 *
 * {@see self::Unconfigured} is the load-bearing one: a self-hosted install
 * typically has a single provider key, so without it the status page would
 * paint over a hundred models red and look like a broken product.
 */
enum ModelHealthState: string
{
    /** Offered by the provider and no recent failures. */
    case Online = 'online';

    /** Reachable but failing more often than the threshold allows. */
    case Degraded = 'degraded';

    /** Gone from the provider's catalog, or failing permanently. */
    case Offline = 'offline';

    /** The provider has no credentials on this install — not a fault. */
    case Unconfigured = 'unconfigured';

    /** Never probed and never used, so there is nothing to report yet. */
    case Unknown = 'unknown';

    /** Should this state draw the operator's attention? */
    public function needsAttention(): bool
    {
        return self::Degraded === $this || self::Offline === $this;
    }
}
