<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * One rule for every account status and every entry point.
 *
 * Active uses the platform. A pending-payment account may sign in, refresh,
 * be impersonated and leave impersonation, so it can reach checkout, and
 * nothing else. Suspended and banned may not enter at all. Any other status
 * is refused too.
 */
final class AccountAccess
{
    public const SUSPENDED = 'ACCOUNT_SUSPENDED';
    public const PENDING = 'ACCOUNT_PENDING_PAYMENT';

    public const SUSPENDED_MESSAGE = 'This account has been suspended. Please contact support.';
    public const PENDING_MESSAGE = 'This account is waiting for payment. Finish checkout to use Synaplan.';

    /**
     * Null when the account may use the door. Otherwise the error code.
     */
    public static function code(?string $status, AccountEntry $entry): ?string
    {
        if (User::ACCOUNT_STATUS_ACTIVE === $status) {
            return null;
        }

        if (User::ACCOUNT_STATUS_PENDING_PAYMENT === $status && $entry->allowsPendingPayment()) {
            return null;
        }

        if (User::ACCOUNT_STATUS_PENDING_PAYMENT === $status) {
            return self::PENDING;
        }

        return self::SUSPENDED;
    }

    public static function forUser(User $user, AccountEntry $entry): ?string
    {
        return $entry->allowsPendingPayment() ? self::signIn($user) : self::credential($user);
    }

    public static function signIn(User $user): ?string
    {
        return $user->canSignIn() ? null : self::SUSPENDED;
    }

    public static function credential(User $user): ?string
    {
        if ($user->isActive()) {
            return null;
        }

        return $user->isPendingPayment() ? self::PENDING : self::SUSPENDED;
    }

    public static function message(string $code): string
    {
        return self::PENDING === $code ? self::PENDING_MESSAGE : self::SUSPENDED_MESSAGE;
    }
}
