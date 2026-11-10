<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Command\LevelGrantsCommand;
use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Service\Iam\LevelGrantService;
use App\Service\Iam\LevelGrantSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The level is the highest live grant. A channel only takes back its own.
 */
final class LevelGrantSequenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LevelGrantService $grants;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->grants = static::getContainer()->get(LevelGrantService::class);
    }

    public function testAnAdministratorBusinessSurvivesStripeProComingAndGoing(): void
    {
        $user = $this->account('NEW');
        $this->grants->assignFromAdministrator($user, 'BUSINESS', null);
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_a', null);

        self::assertSame('BUSINESS', $user->getUserLevel());
        self::assertSame(['stripe', 'admin'], array_column($this->grants->views($user), 'source'));
        $this->grants->remove($user, LevelGrantSource::STRIPE);
        self::assertSame('BUSINESS', $user->getUserLevel());
    }

    public function testALowerPickLeavesTheHigherGrantAndSaysSo(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'BUSINESS', 'sub_kept', null);
        $notice = $this->grants->assignFromAdministrator($user, 'PRO', null);

        self::assertSame('BUSINESS', $user->getUserLevel());
        self::assertSame(['kind' => 'kept', 'level' => 'BUSINESS', 'source' => 'stripe'], $notice);
    }

    public function testChoosingNewWithoutAnAdministratorGrantDoesNotInventARemoval(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_new', null);
        $notice = $this->grants->assignFromAdministrator($user, 'NEW', null);

        self::assertSame('PRO', $user->getUserLevel());
        self::assertSame('kept', $notice['kind'] ?? null);
    }

    public function testChoosingNewRemovesOnlyTheAdministratorGrant(): void
    {
        $user = $this->account('NEW');
        $this->grants->assignFromAdministrator($user, 'TEAM', null);
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_rm', null);
        $notice = $this->grants->assignFromAdministrator($user, 'NEW', null);

        self::assertSame('PRO', $user->getUserLevel());
        self::assertSame('removed', $notice['kind'] ?? null);
        self::assertSame('TEAM', $notice['removed'] ?? null);
        self::assertSame('stripe', $notice['source'] ?? null);
        self::assertSame(['stripe'], array_column($this->grants->views($user), 'source'));
    }

    public function testSettingTheStripeTierAgainKeepsItAfterCancellation(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'TEAM', 'sub_b', null);
        $this->grants->assignFromAdministrator($user, 'TEAM', null);

        self::assertSame('TEAM', $user->getUserLevel());
        $this->grants->remove($user, LevelGrantSource::STRIPE);
        self::assertSame('TEAM', $user->getUserLevel());
    }

    public function testAStripePlanSwitchReplacesOnlyTheStripeGrant(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'TEAM', 'sub_c', null);
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_c', null);

        self::assertSame('PRO', $user->getUserLevel());
    }

    public function testAnAbandonedCheckoutDoesNotTakeASignUpCode(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::CODE, 'TEAM', '4', null);
        $this->grants->remove($user, LevelGrantSource::STRIPE);

        self::assertSame('TEAM', $user->getUserLevel());
    }

    public function testLosingTheDirectoryRoleLeavesThePaidTier(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_e', null);
        $this->grants->replace($user, LevelGrantSource::DIRECTORY, 'ADMIN', null, null);
        self::assertSame('ADMIN', $user->getUserLevel());

        $this->grants->remove($user, LevelGrantSource::DIRECTORY);
        self::assertSame('PRO', $user->getUserLevel());
    }

    public function testALapsedAppStorePurchaseLeavesTheAdministratorGrant(): void
    {
        $user = $this->account('NEW');
        $this->grants->assignFromAdministrator($user, 'TEAM', null);
        $this->grants->replace($user, LevelGrantSource::IAP, 'PRO', null, null);
        self::assertSame('TEAM', $user->getUserLevel());

        $this->grants->remove($user, LevelGrantSource::IAP);
        self::assertSame('TEAM', $user->getUserLevel());

        $higher = $this->account('NEW');
        $this->grants->assignFromAdministrator($higher, 'PRO', null);
        $this->grants->replace($higher, LevelGrantSource::IAP, 'BUSINESS', null, null);
        self::assertSame('BUSINESS', $higher->getUserLevel());
        $this->grants->remove($higher, LevelGrantSource::IAP);
        self::assertSame('PRO', $higher->getUserLevel());
    }

    public function testBillingDoesNotChangeAnAdministrator(): void
    {
        $user = $this->account('NEW');
        $this->grants->replace($user, LevelGrantSource::BOOTSTRAP, 'ADMIN', null, null);
        $this->grants->replace($user, LevelGrantSource::STRIPE, 'PRO', 'sub_g', null);
        $this->grants->remove($user, LevelGrantSource::STRIPE);

        self::assertSame('ADMIN', $user->getUserLevel());
    }

    public function testALegacyStripeOnlyAccountDropsWhenTheSubscriptionEnds(): void
    {
        $user = $this->account('PRO', $this->stripe('PRO'));
        $this->grants->remove($user, LevelGrantSource::STRIPE);

        self::assertSame('NEW', $user->getUserLevel());
    }

    public function testALegacyHigherLevelSurvivesTheStripeCancellation(): void
    {
        $user = $this->account('BUSINESS', $this->stripe('PRO'));
        $this->grants->remove($user, LevelGrantSource::STRIPE);

        self::assertSame('BUSINESS', $user->getUserLevel());
    }

    public function testALegacySameTierAdministratorChangeSurvivesCancellation(): void
    {
        $user = $this->account('PRO', $this->stripe('PRO'));
        $entry = new AuditLogEntry();
        $entry->setActorId(1);
        $entry->setAction('admin.user_level_change');
        $entry->setResourceKind('user');
        $entry->setResourceId((string) $user->getId());
        $entry->setSubject(['new_level' => 'PRO']);
        $this->em->persist($entry);
        $this->em->flush();

        $this->grants->remove($user, LevelGrantSource::STRIPE);

        self::assertSame('PRO', $user->getUserLevel());
    }

    public function testTheCommandIsADryRunUntilApplyAndThenChangesNothing(): void
    {
        $user = $this->account('BUSINESS');
        $command = new CommandTester(static::getContainer()->get(LevelGrantsCommand::class));

        $command->execute([]);
        self::assertSame(0, $this->grantCount($user));
        self::assertStringContainsString((string) $user->getMail(), $command->getDisplay());

        $this->em->beginTransaction();
        try {
            $command->execute(['--apply' => true]);
            $written = $this->grantCount($user);
            self::assertGreaterThan(0, $written);
            self::assertSame('BUSINESS', $this->fresh($user)->getUserLevel());

            $command->execute(['--apply' => true]);
            self::assertSame($written, $this->grantCount($user));
        } finally {
            $this->em->rollback();
        }
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function account(string $level, array $subscription = []): User
    {
        $user = new User();
        $user->setMail('grants-'.bin2hex(random_bytes(6)).'@synaplan.test');
        $user->setType('WEB');
        $user->setProviderId('grants-test');
        $user->setUserLevel($level);
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setEmailVerified(true);
        $user->setPaymentDetails([] === $subscription ? [] : ['subscription' => $subscription]);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function stripe(string $plan): array
    {
        return [
            'source' => 'stripe',
            'status' => 'active',
            'plan' => $plan,
            'stripe_subscription_id' => 'sub_legacy',
            'subscription_start' => time() - 100,
        ];
    }

    private function grantCount(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSER_LEVEL_GRANTS WHERE BUSERID = :id',
            ['id' => (int) $user->getId()],
        );
    }

    private function fresh(User $user): User
    {
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}
