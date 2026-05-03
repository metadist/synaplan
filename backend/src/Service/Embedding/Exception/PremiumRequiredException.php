<?php

declare(strict_types=1);

namespace App\Service\Embedding\Exception;

/**
 * Thrown by EmbeddingModelChangeGuard when a user without an active
 * paid subscription tries to switch the active VECTORIZE model.
 *
 * Carries the user's current rate-limit level so the controller can
 * include it in the API response without re-querying the user. The
 * frontend uses it to show the appropriate upgrade CTA.
 */
final class PremiumRequiredException extends \DomainException
{
    public function __construct(
        public readonly string $currentLevel,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Switching the embedding model requires an active paid subscription. Current level: %s.',
                $currentLevel,
            ),
        );
    }
}
