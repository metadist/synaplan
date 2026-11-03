<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

/**
 * The only credential a WOPI request carries: one file, one user, a short life.
 * It is not a session token and must not be accepted by any other route.
 */
final readonly class WopiAccess
{
    public function __construct(
        public int $fileId,
        public int $userId,
        public int $expiresAt,
    ) {
    }
}
