<?php

declare(strict_types=1);

namespace App\AI\Service;

/**
 * Verdict of asking a provider about one specific model id.
 */
enum ModelProbeResult
{
    /** The provider still knows this model. */
    case Alive;

    /** The provider answered that this model does not exist. */
    case Gone;

    /** No usable answer — rate limit, auth problem, outage, network error. */
    case Inconclusive;
}
