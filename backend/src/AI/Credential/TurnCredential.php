<?php

declare(strict_types=1);

namespace App\AI\Credential;

/**
 * Whose key pays for a turn. The key itself stays on this object and is
 * not written to Redis. `keySource` is the non-secret half a usage row stores.
 */
final readonly class TurnCredential
{
    public function __construct(
        public string $provider,
        public string $key,
        public string $source,
        public ?int $userId,
        public ?int $endpointId,
        public bool $operatorFallback,
        public string $keySource,
        public bool $present,
    ) {
    }

    public static function local(string $provider, ?int $userId): self
    {
        return new self($provider, 'local', 'operator', $userId, null, false, 'operator', true);
    }

    public static function missing(string $provider, ?int $userId): self
    {
        return new self($provider, '', 'operator', $userId, null, false, 'operator', false);
    }
}
