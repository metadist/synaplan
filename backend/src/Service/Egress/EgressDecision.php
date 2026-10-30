<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * Whether one destination may be contacted, and which rule said so.
 * `allowed` is false when `enforce` must refuse. The reason is a short
 * token, never the URL.
 */
final readonly class EgressDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $rule,
        public string $reason,
    ) {
    }

    public static function allow(string $rule, string $reason): self
    {
        return new self(true, $rule, $reason);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
