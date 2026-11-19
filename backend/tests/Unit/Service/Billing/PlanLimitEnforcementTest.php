<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Repository\ConfigRepository;
use App\Service\Billing\BillingService;
use App\Service\Billing\PlanLimitEnforcement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanLimitEnforcementTest extends TestCase
{
    public function testBillingAlwaysEnforcesWithoutReadingTheFlag(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::never())->method('getValue');

        $enforcement = new PlanLimitEnforcement(
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $configRepository,
        );

        self::assertTrue($enforcement->isEnforced());
    }

    #[DataProvider('storedFlags')]
    public function testWithoutBillingTheStoredFlagDecides(?string $stored, bool $expected): void
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturnMap([
            [0, PlanLimitEnforcement::CONFIG_GROUP, PlanLimitEnforcement::KEY_WITHOUT_BILLING, $stored],
        ]);

        $enforcement = new PlanLimitEnforcement(new BillingService('', ''), $configRepository);

        self::assertSame($expected, $enforcement->isEnforced());
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function storedFlags(): iterable
    {
        yield 'installation from before the flag' => [null, false];
        yield 'new installation' => ['true', true];
        yield 'stored as 1' => ['1', true];
        yield 'switched off' => ['false', false];
        yield 'garbage' => ['maybe', false];
    }
}
