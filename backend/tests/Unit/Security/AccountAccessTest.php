<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\AccountAccess;
use App\Security\AccountEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Account status against every entry point.
 *
 * Active may use all of them. Pending payment may only sign in (password,
 * refresh, Google, GitHub, Apple, OIDC browser and bearer), be impersonated,
 * and leave impersonation, so checkout stays reachable. Suspended and banned
 * may not enter. The doors that mint a session or spend the platform call
 * {@see AccountAccess} themselves; the firewall user checker and the
 * pending-payment subscriber cover the authenticated HTTP routes.
 */
final class AccountAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: AccountEntry, 2: ?string}>
     */
    public static function matrix(): iterable
    {
        foreach (AccountEntry::cases() as $entry) {
            yield 'active '.$entry->value => [User::ACCOUNT_STATUS_ACTIVE, $entry, null];
            yield 'pending '.$entry->value => [
                User::ACCOUNT_STATUS_PENDING_PAYMENT,
                $entry,
                $entry->allowsPendingPayment() ? null : AccountAccess::PENDING,
            ];
            yield 'suspended '.$entry->value => [User::ACCOUNT_STATUS_SUSPENDED, $entry, AccountAccess::SUSPENDED];
            yield 'banned '.$entry->value => [User::ACCOUNT_STATUS_BANNED, $entry, AccountAccess::SUSPENDED];
        }
    }

    #[DataProvider('matrix')]
    public function testTheStatusDecidesTheDoor(string $status, AccountEntry $entry, ?string $code): void
    {
        self::assertSame($code, AccountAccess::code($status, $entry));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::code('retired', AccountEntry::Password));
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::code(null, AccountEntry::ApiKey));
    }

    public function testTheSentencesMatchTheCode(): void
    {
        self::assertSame(
            'This account has been suspended. Please contact support.',
            AccountAccess::message(AccountAccess::SUSPENDED),
        );
        self::assertSame(
            'This account is waiting for payment. Finish checkout to use Synaplan.',
            AccountAccess::message(AccountAccess::PENDING),
        );
    }
}
