<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Service\BillingService;

/**
 * MOBILE-APP SEAM (Epic 5.4): the native in-app-purchase channels.
 *
 * Mirrors {@see BillingService::SOURCE_APPLE} / `SOURCE_GOOGLE` — those string
 * constants are what gets persisted in `BPAYMENTDETAILS.subscription.source`,
 * this enum is the type-safe handle used while validating a purchase.
 */
enum IapPlatform: string
{
    case APPLE = 'apple';
    case GOOGLE = 'google';

    /** The persisted subscription `source` value for this channel. */
    public function source(): string
    {
        return match ($this) {
            self::APPLE => BillingService::SOURCE_APPLE,
            self::GOOGLE => BillingService::SOURCE_GOOGLE,
        };
    }

    public static function tryFromSource(?string $source): ?self
    {
        return match ($source) {
            BillingService::SOURCE_APPLE => self::APPLE,
            BillingService::SOURCE_GOOGLE => self::GOOGLE,
            default => null,
        };
    }
}
