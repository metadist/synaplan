<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Exception\ApiKeyPlanRequiredException;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The gate has two jobs: keep the hosted SaaS behaviour byte-for-byte, and open
 * every paid-tier check on an install that sells nothing.
 */
final class PremiumFeatureGateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function freeLevels(): iterable
    {
        yield 'new' => ['NEW'];
        yield 'anonymous' => ['ANONYMOUS'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paidLevels(): iterable
    {
        yield 'pro' => ['PRO'];
        yield 'team' => ['TEAM'];
        yield 'business' => ['BUSINESS'];
        yield 'admin' => ['ADMIN'];
    }

    #[DataProvider('freeLevels')]
    public function testFreeLevelIsBlockedWhenBillingIsEnabled(string $level): void
    {
        self::assertFalse($this->gate(billingEnabled: true)->isUnlockedFor($this->user($level)));
    }

    #[DataProvider('paidLevels')]
    public function testPaidLevelIsUnlockedWhenBillingIsEnabled(string $level): void
    {
        self::assertTrue($this->gate(billingEnabled: true)->isUnlockedFor($this->user($level)));
    }

    #[DataProvider('freeLevels')]
    public function testFreeLevelIsUnlockedWithoutBilling(string $level): void
    {
        self::assertTrue($this->gate(billingEnabled: false)->isUnlockedFor($this->user($level)));
    }

    public function testPlaceholderStripeConfigCountsAsNoBilling(): void
    {
        $gate = new PremiumFeatureGate(new BillingService('sk_test_your_key_here', 'price_xxx'));

        self::assertTrue($gate->isUnlockedFor($this->user('NEW')));
    }

    public function testCostBudgetIsEnforcedOnlyWithBothFlagAndBilling(): void
    {
        self::assertTrue($this->gate(billingEnabled: true, gateFlag: true)->isCostBudgetEnforced());
        self::assertFalse($this->gate(billingEnabled: true, gateFlag: false)->isCostBudgetEnforced());
        self::assertFalse($this->gate(billingEnabled: false, gateFlag: true)->isCostBudgetEnforced());
    }

    public function testAnAdministratorsSessionDoesNotEndSomeoneElsesAccount(): void
    {
        $target = $this->user('PRO');
        $target->setEmailVerified(true);
        $target->recordMailboxProof();
        $this->setId($target, 7);
        $request = Request::create('/api/v1/apikeys');
        $request->attributes->set('access_token_user_id', 3);
        $request->attributes->set('access_token_mbg', 0);
        $stack = new RequestStack();
        $stack->push($request);

        $gate = new PremiumFeatureGate(new BillingService('sk_live_test', 'price_1RealPro'), false, $stack);

        $gate->assertApiKeysAllowed($target);
        self::assertSame(1, $target->getMailboxGeneration());
    }

    public function testATokenFromBeforeTheProofIsRefusedInTheSameSecond(): void
    {
        $owner = $this->user('PRO');
        $owner->setEmailVerified(true);
        $owner->recordMailboxProof();
        $this->setId($owner, 4);
        $request = Request::create('/api/v1/apikeys');
        $request->attributes->set('access_token_user_id', 4);
        $request->attributes->set('access_token_mbg', 0);
        $stack = new RequestStack();
        $stack->push($request);
        $gate = new PremiumFeatureGate(new BillingService('sk_live_test', 'price_1RealPro'), false, $stack);

        $this->expectException(ApiKeyPlanRequiredException::class);
        $gate->assertApiKeysAllowed($owner);
    }

    public function testIsPaidLevelIgnoresCasingAndBillingState(): void
    {
        $gate = $this->gate(billingEnabled: false);

        self::assertTrue($gate->isPaidLevel('pro'));
        self::assertFalse($gate->isPaidLevel('new'));
    }

    private function gate(bool $billingEnabled, bool $gateFlag = false): PremiumFeatureGate
    {
        return new PremiumFeatureGate(
            $billingEnabled
                ? new BillingService('sk_live_test', 'price_1RealPro')
                : new BillingService('', ''),
            $gateFlag,
        );
    }

    private function setId(User $user, int $id): void
    {
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, $id);
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }
}
