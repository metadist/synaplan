<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Chat\Loop\ChatRunCostLimit;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;

/**
 * The share of the monthly budget one chat run may spend, as the person
 * chose it. A share can never be more than the monthly budget.
 */
final class ChatRunCostLimitTest extends TestCase
{
    /** @var array<string, string> */
    private array $rows = [];

    public function testWithoutAChoiceARunMaySpendTenPercent(): void
    {
        $allowance = $this->limit('20.00', '15.50')->allowance($this->user());

        self::assertTrue($allowance->allowed);
        self::assertSame(2_000_000, $allowance->runCapMicro);
        self::assertSame(15_500_000, $allowance->leftMicro);
        self::assertSame(20_000_000, $allowance->monthlyMicro);
    }

    public function testTheChosenShareIsStoredAndUsed(): void
    {
        $limit = $this->limit('20.00', '20.00');
        $limit->save($this->user(), 25);

        self::assertSame('25', $this->rows['7.LOOP.RUN_COST_PERCENT']);
        self::assertSame(25, $limit->percent($this->user()));
        self::assertSame(5_000_000, $limit->allowance($this->user())->runCapMicro);
    }

    public function testAShareThatIsNotOfferedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->limit('20.00', '20.00')->save($this->user(), 150);
    }

    public function testTheDefaultOrNullDropsTheStoredChoice(): void
    {
        $limit = $this->limit('20.00', '20.00');
        $limit->save($this->user(), 50);
        $limit->save($this->user(), null);

        self::assertArrayNotHasKey('7.LOOP.RUN_COST_PERCENT', $this->rows);
        self::assertSame(ChatRunCostLimit::DEFAULT_PERCENT, $limit->percent($this->user()));
    }

    public function testABrokenStoredShareFallsBackToTheDefault(): void
    {
        $this->rows['7.LOOP.RUN_COST_PERCENT'] = '70; DROP';

        self::assertSame(ChatRunCostLimit::DEFAULT_PERCENT, $this->limit('20.00', '20.00')->percent($this->user()));
    }

    public function testAPlanWithoutAMonthlyBudgetHasNoLimitPerRun(): void
    {
        $allowance = $this->limit('0.00', '0.00')->allowance($this->user());

        self::assertTrue($allowance->allowed);
        self::assertNull($allowance->runCapMicro);
        self::assertNull($allowance->leftMicro);
    }

    private function limit(string $budget, string $remaining): ChatRunCostLimit
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(fn (int $owner, string $group, string $setting): ?string => $this->rows[$owner.'.'.$group.'.'.$setting] ?? null);
        $config->method('setValue')->willReturnCallback(function (int $owner, string $group, string $setting, string $value): Config {
            $this->rows[$owner.'.'.$group.'.'.$setting] = $value;

            return new Config();
        });
        $config->method('deleteValue')->willReturnCallback(function (int $owner, string $group, string $setting): bool {
            $key = $owner.'.'.$group.'.'.$setting;
            $had = isset($this->rows[$key]);
            unset($this->rows[$key]);

            return $had;
        });
        $rates = $this->createStub(RateLimitService::class);
        $rates->method('checkCostBudget')->willReturn([
            'allowed' => (float) $remaining > 0 || 0.0 === (float) $budget,
            'used_cost' => '0.00',
            'raw_cost' => '0.00',
            'markup_percent' => 0.0,
            'base_budget' => $budget,
            'topups' => '0.00',
            'budget' => $budget,
            'remaining' => $remaining,
            'percent' => 0.0,
            'period_start' => 0,
            'period_end' => 0,
        ]);

        return new ChatRunCostLimit($config, $rates);
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
