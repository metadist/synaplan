<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Tell one cloud that a person's link is gone. Queued after the revocation
 * is committed, so the request does not wait on the cloud.
 *
 * Queued in: async_index
 * Handled by: NotifyIdentityRevokedHandler
 */
final readonly class NotifyIdentityRevoked
{
    public function __construct(
        public string $instanceId,
        public string $identityId,
    ) {
    }
}
