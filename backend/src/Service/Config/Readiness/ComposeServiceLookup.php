<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

/**
 * Does a compose service exist on this network? Docker's DNS only knows the
 * name while the container exists, so an unresolvable name means the service
 * is not part of this stack (its profile is off), not that it is starting.
 *
 * The lookup is silenced. A missing name is the normal fresh-clone case, and
 * the dev error handler would otherwise turn that warning into a failed request.
 */
final readonly class ComposeServiceLookup
{
    public function exists(string $service): bool
    {
        return false !== @gethostbynamel($service);
    }
}
