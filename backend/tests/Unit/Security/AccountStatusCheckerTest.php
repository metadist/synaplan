<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\AccountStatusChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * The account states and what each may do (admission sign-up, AS1):
 * active uses the platform, pending_payment may only sign in (to pay),
 * suspended and banned may not sign in at all.
 */
final class AccountStatusCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function statusProvider(): iterable
    {
        //                                   status                                 isActive canSignIn
        yield 'active' => [User::ACCOUNT_STATUS_ACTIVE, true, true];
        yield 'pending payment' => [User::ACCOUNT_STATUS_PENDING_PAYMENT, false, true];
        yield 'suspended' => [User::ACCOUNT_STATUS_SUSPENDED, false, false];
        yield 'banned' => [User::ACCOUNT_STATUS_BANNED, false, false];
    }

    #[DataProvider('statusProvider')]
    public function testEachStatusHasExactlyOneMeaning(string $status, bool $isActive, bool $canSignIn): void
    {
        $user = (new User())->setAccountStatus($status);

        self::assertSame($isActive, $user->isActive());
        self::assertSame($canSignIn, $user->canSignIn());
        self::assertSame(User::ACCOUNT_STATUS_PENDING_PAYMENT === $status, $user->isPendingPayment());
    }

    #[DataProvider('statusProvider')]
    public function testTheCheckerLetsExactlyTheSignInStatesThrough(string $status, bool $isActive, bool $canSignIn): void
    {
        $user = (new User())->setAccountStatus($status);
        $checker = new AccountStatusChecker();

        if (!$canSignIn) {
            $this->expectException(CustomUserMessageAccountStatusException::class);
        }
        $checker->checkPreAuth($user);
        if ($canSignIn) {
            $this->addToAssertionCount(1);
        }
    }

    public function testADefaultUserIsActive(): void
    {
        self::assertTrue((new User())->isActive());
    }
}
