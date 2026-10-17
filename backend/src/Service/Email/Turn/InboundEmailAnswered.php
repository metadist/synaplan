<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

/**
 * An inbound email the intake answers without a turn: an invalid payload, a
 * redelivery, a refused sender or a spent limit. Carries the JSON answer and
 * the HTTP status the relay reads.
 */
final class InboundEmailAnswered extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly array $payload,
        public readonly int $httpStatus,
    ) {
        parent::__construct(sprintf('Inbound email answered by the intake (%d)', $httpStatus));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $httpStatus): self
    {
        return new self($payload, $httpStatus);
    }
}
