<?php

declare(strict_types=1);

namespace App\Service\Embedding\Exception;

/**
 * Thrown by EmbeddingModelChangeGuard when a switch is requested less
 * than EmbeddingModelChangeGuard::COOLDOWN_SECONDS after the previous
 * one for the same scope. Protects against accidental double-clicks
 * and runaway automations.
 */
final class CooldownActiveException extends \DomainException
{
    public function __construct(
        public readonly int $cooldownEndsAt,
        public readonly int $secondsRemaining,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Cooldown active. Next switch possible in %d seconds.',
                $secondsRemaining,
            ),
        );
    }
}
