<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\DTO\CostResult;
use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\ConfigRepository;
use App\Repository\VoiceSessionRepository;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Usage\CostCalculationService;
use App\Service\Voice\VoiceLiveMeter;
use App\Service\Voice\VoiceLiveUsageService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class VoiceLiveUsageServiceTest extends TestCase
{
    public function testAllowedSecondsReservesRoomForAnswers(): void
    {
        $service = $this->service(
            remaining: '0.10',
            budget: '1.00',
            enforced: true,
        );

        // $0.05/min * 1.5 answer allowance = $0.00125/s. $0.10 buys 80 seconds.
        $this->assertSame(80, $service->allowedSeconds($this->user(), $this->perSecondModel()));
    }

    public function testTickWarnsInsideTheLastMinute(): void
    {
        $service = $this->service(
            remaining: '0.05',
            budget: '1.00',
            enforced: true,
        );
        $session = $this->session();

        $decision = $service->tick($session, new VoiceLiveMeter(seconds: 10));

        $this->assertSame('warn', $decision->action);
        $this->assertSame(40, $decision->allowedSeconds);
        $this->assertSame('limit_budget', $decision->reason);
    }

    public function testTickWrapsUpThenStopsWhenTheBudgetIsGone(): void
    {
        $service = $this->service(
            remaining: '0.00',
            budget: '1.00',
            enforced: true,
        );
        $session = $this->session();

        $first = $service->tick($session, new VoiceLiveMeter(seconds: 10));
        $this->assertSame('wrap_up', $first->action);
        $this->assertSame('ending', $session->getStatus());

        $second = $service->tick($session, new VoiceLiveMeter(seconds: 12));
        $this->assertSame('stop', $second->action);
        $this->assertSame('limit_budget', $second->reason);
    }

    public function testNoBudgetMeansTheMaxLengthOnly(): void
    {
        $service = $this->service(
            remaining: '0.00',
            budget: '0.00',
            enforced: true,
            maxSeconds: '120',
        );

        $this->assertSame(120, $service->allowedSeconds($this->user(), $this->perSecondModel()));

        $decision = $service->tick($this->session(), new VoiceLiveMeter(seconds: 30));
        $this->assertSame('continue', $decision->action);
        $this->assertSame(120, $decision->allowedSeconds);
    }

    public function testGateOffIgnoresTheBudget(): void
    {
        $service = $this->service(
            remaining: '0.00',
            budget: '1.00',
            enforced: false,
        );

        $this->assertSame(1800, $service->allowedSeconds($this->user(), $this->perSecondModel()));
    }

    private function service(string $remaining, string $budget, bool $enforced, ?string $maxSeconds = null): VoiceLiveUsageService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(json_encode([
            'voice_cost' => '0.000000',
            'answer_cost' => '0.000000',
            'answer_prompt_tokens' => 0,
            'answer_completion_tokens' => 0,
            'zero_cost' => false,
        ], JSON_THROW_ON_ERROR));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('find')->willReturnCallback(function (string $class): User|Model|null {
            return match ($class) {
                User::class => $this->user(),
                Model::class => $this->perSecondModel(),
                default => null,
            };
        });

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('getMarkupPercent')->willReturn(0.0);
        $limits->method('checkCostBudget')->willReturn([
            'allowed' => (float) $remaining > 0,
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

        $gate = $this->createStub(PremiumFeatureGate::class);
        $gate->method('isCostBudgetEnforced')->willReturn($enforced);

        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn($maxSeconds);

        $costs = $this->createStub(CostCalculationService::class);
        $costs->method('calculateVoiceLiveCost')->willReturn(new CostResult(
            '0.000000',
            '0.000000',
            '0.000000',
            '0.000000',
            [],
            0,
        ));

        return new VoiceLiveUsageService(
            $em,
            $this->createStub(VoiceSessionRepository::class),
            $costs,
            $limits,
            $gate,
            $config,
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function perSecondModel(): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn(389);
        $model->method('getPriceIn')->willReturn(0.05);
        $model->method('getInUnit')->willReturn('permin');
        $model->method('getJson')->willReturn(['pricing_mode' => 'per_second']);

        return $model;
    }

    private function session(): VoiceSession
    {
        return (new VoiceSession())
            ->setUserId(7)
            ->setModelId(389)
            ->setUseLogId(99)
            ->setStatus('live');
    }
}
