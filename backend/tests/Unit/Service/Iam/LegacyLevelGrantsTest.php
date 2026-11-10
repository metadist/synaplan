<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use App\Service\Iam\LegacyLevelGrants;
use PHPUnit\Framework\TestCase;

final class LegacyLevelGrantsTest extends TestCase
{
    public function testAnActiveStripeSubscriptionOwnsAnEqualLevel(): void
    {
        $plan = $this->planner()->plan($this->user('PRO', [
            'source' => 'stripe',
            'status' => 'active',
            'plan' => 'PRO',
            'stripe_subscription_id' => 'sub_1',
        ]), false);

        self::assertTrue($plan->rule1Only);
        self::assertSame(['stripe' => 'PRO'], $this->levels($plan->bySource()));
    }

    public function testAHigherLevelIsAnAdministratorGrantOnTopOfStripe(): void
    {
        $plan = $this->planner()->plan($this->user('BUSINESS', [
            'source' => 'stripe',
            'status' => 'active',
            'plan' => 'PRO',
            'stripe_subscription_id' => 'sub_1',
        ]), false);

        self::assertFalse($plan->rule1Only);
        self::assertSame(['stripe' => 'PRO', 'admin' => 'BUSINESS'], $this->levels($plan->bySource()));
    }

    public function testALevelWithNoBillingIsAnAdministratorGrant(): void
    {
        $plan = $this->planner()->plan($this->user('TEAM', []), false);

        self::assertSame(['admin' => 'TEAM'], $this->levels($plan->bySource()));
        self::assertTrue($plan->interesting());
    }

    public function testNewWithNoBillingHasNoGrantsAndStaysQuiet(): void
    {
        $plan = $this->planner()->plan($this->user('NEW', []), false);

        self::assertSame([], $plan->grants);
        self::assertFalse($plan->interesting());
    }

    public function testAnActiveAppStoreSubscriptionIsAnIapGrantWithoutTheToken(): void
    {
        $plan = $this->planner()->plan($this->user('PRO', [
            'source' => 'apple',
            'status' => 'grace_period',
            'plan' => 'PRO',
            'purchase_token' => 'secret-token',
        ]), false);

        self::assertTrue($plan->rule1Only);
        self::assertNull($plan->bySource()['iap']['ref']);
    }

    public function testADirectoryRoleReplacesTheBootstrapGrant(): void
    {
        $user = $this->user('ADMIN', []);
        $user->setUserDetails(['oidc_roles' => ['Administrator']]);
        $plan = $this->planner()->plan($user, false);

        self::assertSame(['directory' => 'ADMIN'], $this->levels($plan->bySource()));
        self::assertArrayNotHasKey('bootstrap', $plan->bySource());
        self::assertArrayNotHasKey('admin', $plan->bySource());
    }

    public function testAnAdministratorWithoutADirectoryRoleIsBootstrap(): void
    {
        $plan = $this->planner()->plan($this->user('ADMIN', []), false);

        self::assertSame(['bootstrap' => 'ADMIN'], $this->levels($plan->bySource()));
    }

    public function testAnActiveStoreRecordWithoutAPlanOwnsTheCurrentPaidLevel(): void
    {
        $plan = $this->planner()->plan($this->user('PRO', [
            'source' => 'apple',
            'status' => 'active',
        ]), false);

        self::assertTrue($plan->rule1Only);
        self::assertSame(['iap' => 'PRO'], $this->levels($plan->bySource()));
    }

    public function testALegacyStripeSubscriptionWithoutASourceIsStillStripe(): void
    {
        $plan = $this->planner()->plan($this->user('PRO', [
            'status' => 'active',
            'plan' => 'PRO',
            'stripe_subscription_id' => 'sub_legacy',
        ]), false);

        self::assertSame(['stripe' => 'PRO'], $this->levels($plan->bySource()));
    }

    private function planner(): LegacyLevelGrants
    {
        return new LegacyLevelGrants($this->createStub(AuditLogEntryRepository::class), 'administrator');
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function user(string $level, array $subscription): User
    {
        $user = new User();
        $user->setMail('legacy@synaplan.test');
        $user->setUserLevel($level);
        $user->setPaymentDetails([] === $subscription ? [] : ['subscription' => $subscription]);

        return $user;
    }

    /**
     * @param array<string, array{level: string}> $rows
     *
     * @return array<string, string>
     */
    private function levels(array $rows): array
    {
        $levels = [];
        foreach ($rows as $source => $row) {
            $levels[$source] = $row['level'];
        }

        return $levels;
    }
}
