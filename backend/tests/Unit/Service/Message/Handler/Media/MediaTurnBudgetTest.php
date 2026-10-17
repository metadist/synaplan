<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaTurnBudget;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaTurnBudgetTest extends TestCase
{
    /** @var list<string> */
    private array $checks = [];

    public function testATurnWithoutAnAccountIsNotChecked(): void
    {
        self::assertNull($this->budget()->refusal($this->request('image'), null));
        self::assertSame([], $this->checks);
    }

    public function testASpentMediaLimitRefusesBeforeTheBudgetIsRead(): void
    {
        $refusal = $this->budget(limit: ['allowed' => false, 'used' => 3, 'limit' => 3], enforced: true)->refusal($this->request('video'), $this->createStub(User::class));
        self::assertNotNull($refusal);

        self::assertSame('Rate limit exceeded for VIDEOS. Used: 3/3', $refusal->text);
        self::assertSame(['error' => 'rate_limit_exceeded', 'action' => 'VIDEOS', 'used' => 3, 'limit' => 3], $refusal->metadata);
        self::assertSame(['limit VIDEOS'], $this->checks);
    }

    public function testASpentBudgetRefusesOnlyWhereBillingEnforcesIt(): void
    {
        $spent = ['allowed' => false, 'budget' => '20.00', 'used_cost' => '20.40', 'remaining' => '0.00'];

        self::assertNull($this->budget(budget: $spent)->refusal($this->request('audio'), $this->createStub(User::class)));
        self::assertSame(['limit AUDIOS'], $this->checks);

        $refusal = $this->budget(budget: $spent, enforced: true)->refusal($this->request('audio', 'de'), $this->createStub(User::class));

        self::assertNotNull($refusal);
        self::assertSame(['error' => 'cost_budget_exceeded', 'code' => 'COST_BUDGET_EXCEEDED', 'limit_type' => 'monthly', 'budget' => '20.00', 'used' => '20.40', 'remaining' => '0.00', 'topup_available' => true], $refusal->metadata);
        self::assertStringStartsWith('Dein monatliches Nutzungsbudget ist aufgebraucht.', $refusal->text);
    }

    /**
     * @param array<string, mixed> $limit
     * @param array<string, mixed> $budget
     */
    private function budget(array $limit = ['allowed' => true], array $budget = ['allowed' => true], bool $enforced = false): MediaTurnBudget
    {
        $this->checks = [];
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturnCallback(function (User $user, string $action) use ($limit): array {
            $this->checks[] = 'limit '.$action;

            return $limit;
        });
        $limits->method('checkCostBudget')->willReturnCallback(function () use ($budget): array {
            $this->checks[] = 'budget';

            return $budget;
        });
        $gate = $this->createStub(PremiumFeatureGate::class);
        $gate->method('isCostBudgetEnforced')->willReturn($enforced);

        return new MediaTurnBudget($this->createStub(EntityManagerInterface::class), $limits, $gate, new NullLogger());
    }

    private function request(string $mediaType, string $language = 'en'): MediaTurnRequest
    {
        return new MediaTurnRequest($this->createStub(Message::class), [], ['language' => $language], [], 'A fox', $mediaType, new MediaReferences([], [], null), new MediaModel(41, 'openai', 'gpt-image-1', []), 7);
    }
}
