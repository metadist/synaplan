<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing\PayFirst;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Billing\PayFirst\PaidAccountCreator;
use App\Service\Billing\PayFirst\PaidAccountMailer;
use App\Service\Billing\StripeEntitlement;
use App\Service\Iam\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The parts of the creator the controller tests cannot reach: a failure after
 * the account row is written leaves nothing behind and mails nobody, and the
 * lock is per address whatever its spelling.
 */
final class PaidAccountCreatorTest extends TestCase
{
    private bool $rolledBack = false;

    public function testAFailureAfterTheAccountRowLeavesNoAccountAndSendsNoMail(): void
    {
        $gateway = new RecordingGateway();
        $checkout = $gateway->paidSession('cs_fail', 'ada@example.test');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByStripeCustomerId')->willReturn(null);
        $users->method('findOtherByNormalizedEmail')->willReturn(null);

        $lifecycle = $this->createMock(UserLifecycleService::class);
        $lifecycle->expects(self::once())->method('createUser')->willReturn(new User());

        $tokens = $this->createMock(VerificationTokenRepository::class);
        $tokens->method('createToken')->willThrowException(new \RuntimeException('Deadlock found'));

        $mailer = $this->createMock(PaidAccountMailer::class);
        $mailer->expects(self::never())->method('accountReady');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $work): mixed {
            try {
                return $work();
            } catch (\Throwable $e) {
                $this->rolledBack = true;

                throw $e;
            }
        });

        $creator = new PaidAccountCreator(
            $gateway,
            $users,
            $lifecycle,
            $this->createMock(StripeEntitlement::class),
            $tokens,
            $mailer,
            $em,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        try {
            $creator->settle($checkout);
            self::fail('The failure must reach the webhook so Stripe retries.');
        } catch (\RuntimeException $e) {
            self::assertSame('Deadlock found', $e->getMessage());
        }
        self::assertTrue($this->rolledBack, 'The account row is written inside the transaction that failed.');
    }

    public function testThePurchaseLockIsPerAddressWhateverItsSpelling(): void
    {
        self::assertSame(PaidAccountCreator::lockKey('ada@example.test'), PaidAccountCreator::lockKey('  ADA@Example.test '));
        self::assertNotSame(PaidAccountCreator::lockKey('ada@example.test'), PaidAccountCreator::lockKey('bea@example.test'));
    }
}
