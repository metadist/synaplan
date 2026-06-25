<?php

declare(strict_types=1);

namespace App\Service\Iap;

/**
 * Outcome of redeeming an IAP receipt for the signed-in user.
 */
final readonly class RedeemResult
{
    public function __construct(
        /** True when a paid tier was granted (active/grace). */
        public bool $granted,
        /** True when the purchase is still PENDING and was intentionally not unlocked. */
        public bool $pending,
        /** The resulting Synaplan tier (NEW when not granted). */
        public string $tier,
        /** Owning channel: 'apple' | 'google'. */
        public string $source,
        /** Normalized subscription status persisted for the user. */
        public string $status,
    ) {
    }

    public static function granted(string $tier, string $source, string $status): self
    {
        return new self(true, false, $tier, $source, $status);
    }

    public static function pending(string $source): self
    {
        return new self(false, true, 'NEW', $source, 'pending');
    }

    public static function notGranted(string $tier, string $source, string $status): self
    {
        return new self(false, false, $tier, $source, $status);
    }
}
