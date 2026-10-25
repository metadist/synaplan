<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReapPendingSignupsCommand;
use App\Entity\User;
use App\Service\Scheduler\ScheduledJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A plan sign-up that never paid is deleted after seven days, through the
 * real erasure path; everything else stays: younger accounts, accounts with
 * a subscription on record, and accounts that are not pending.
 */
final class ReapPendingSignupsCommandTest extends KernelTestCase
{
    private const MAIL_PREFIX = 'reap-pending-';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    public function testDeletesOnlyUnpaidSignupsOlderThanSevenDays(): void
    {
        $old = $this->user('old', User::ACCOUNT_STATUS_PENDING_PAYMENT, 8);
        $young = $this->user('young', User::ACCOUNT_STATUS_PENDING_PAYMENT, 6);
        $paying = $this->user('paying', User::ACCOUNT_STATUS_PENDING_PAYMENT, 8, ['stripe_subscription_id' => 'sub_test_1']);
        $active = $this->user('active', User::ACCOUNT_STATUS_ACTIVE, 30);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 1 pending sign-up(s) older than 7 days', $tester->getDisplay());
        self::assertSame([$young, $paying, $active], $this->remaining([$old, $young, $paying, $active]));
    }

    public function testAPaymentRecordedAfterTheAccountWasLoadedKeepsIt(): void
    {
        $id = $this->user('race', User::ACCOUNT_STATUS_PENDING_PAYMENT, 8);
        // The webhook writes through its own connection: the entity this
        // kernel already holds still shows no subscription.
        $this->em->getConnection()->executeStatement(
            'UPDATE BUSER SET BPAYMENTDETAILS = :details WHERE BID = :id',
            ['details' => json_encode(['subscription' => ['stripe_subscription_id' => 'sub_race']]), 'id' => $id],
        );

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 0 pending sign-up(s)', $tester->getDisplay());
        self::assertSame([$id], $this->remaining([$id]));
    }

    public function testKeptAccountsDoNotHideLaterOnes(): void
    {
        $kept = [];
        for ($i = 0; $i < ReapPendingSignupsCommand::PAGE_SIZE; ++$i) {
            $kept[] = $this->user('kept'.$i, User::ACCOUNT_STATUS_PENDING_PAYMENT, 8, ['stripe_subscription_id' => 'sub_'.$i], false);
        }
        $this->em->flush();
        $kept = array_map(static fn (User $user): int => (int) $user->getId(), $kept);
        $eligible = $this->user('after-kept', User::ACCOUNT_STATUS_PENDING_PAYMENT, 8);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Deleted 1 pending sign-up(s)', $tester->getDisplay());
        self::assertSame([], $this->remaining([$eligible]));
        self::assertCount(ReapPendingSignupsCommand::PAGE_SIZE, $this->remaining($kept));
    }

    public function testDryRunDeletesNothing(): void
    {
        $old = $this->user('dry', User::ACCOUNT_STATUS_PENDING_PAYMENT, 9);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would delete 1 pending sign-up(s)', $tester->getDisplay());
        self::assertSame([$old], $this->remaining([$old]));
    }

    public function testTheSchedulerRunsItEveryHour(): void
    {
        self::assertSame('hourly', ScheduledJobs::laneOf('app:signup:reap-pending'));
        self::assertSame(7, ReapPendingSignupsCommand::PENDING_DAYS);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:signup:reap-pending'));
    }

    /**
     * @param array<string, string> $subscription
     *
     * @return ($flush is true ? int : User)
     */
    private function user(string $name, string $status, int $ageDays, array $subscription = [], bool $flush = true): int|User
    {
        $user = (new User())
            ->setMail(self::MAIL_PREFIX.$name.'-'.uniqid().'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId(self::MAIL_PREFIX.uniqid())
            ->setUserLevel('NEW')
            ->setAccountStatus($status);
        $user->setCreated(date('Y-m-d H:i:s', time() - $ageDays * 86400));
        if ([] !== $subscription) {
            $user->setSubscriptionData($subscription);
        }
        $this->em->persist($user);
        if (!$flush) {
            return $user;
        }
        $this->em->flush();

        return (int) $user->getId();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function remaining(array $ids): array
    {
        $this->em->clear();
        $users = $this->em->getRepository(User::class)->findBy(['id' => $ids], ['id' => 'ASC']);

        return array_map(static fn (User $user): int => (int) $user->getId(), $users);
    }

    private function removeTestUsers(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM BUSER WHERE BMAIL LIKE :prefix',
            ['prefix' => self::MAIL_PREFIX.'%'],
        );
    }
}
