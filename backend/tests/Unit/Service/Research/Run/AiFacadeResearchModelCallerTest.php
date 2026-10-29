<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\Schema\ResearchTriageSchema;
use App\DTO\CostResult;
use App\Service\ModelConfigService;
use App\Service\Research\Run\ResearchLease;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchUnitWorker;
use App\Service\Research\Run\Stage\AiFacadeResearchModelCaller;
use App\Service\Usage\CostCalculationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AiFacadeResearchModelCallerTest extends TestCase
{
    public function testTheCostComesFromTheReportedUsage(): void
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willReturn([
            'content' => "```json\n{\"results\": []}\n```",
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'cached_tokens' => 100],
        ]);
        $costs = $this->createMock(CostCalculationService::class);
        $costs->expects(self::once())
            ->method('calculateCost')
            ->with(1000, 200, 100, 0, 42, null, 0)
            ->willReturn(new CostResult('0.012500', '0.010000', '0.002500', '0.000000', [], 1000));

        $reply = (new AiFacadeResearchModelCaller($ai, $this->models('test'), $costs))
            ->answer(7, 42, 'system', 'user', ResearchTriageSchema::build());

        self::assertTrue($reply->ok);
        self::assertSame(['results' => []], $reply->data);
        self::assertSame(0.0125, $reply->cost);
        self::assertSame('test:model', $reply->modelKey);
    }

    public function testAnUnknownModelIsNotCalled(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');

        $reply = (new AiFacadeResearchModelCaller($ai, $this->models(null), $this->createStub(CostCalculationService::class)))
            ->answer(7, 42, 'system', 'user', null);

        self::assertFalse($reply->ok);
        self::assertFalse($reply->called);
    }

    public function testALiveCallStopsInsideTheLease(): void
    {
        $clock = new MockClock('2026-10-29T12:00:00+00:00');
        $store = new ResearchStateStore(new MemoryResearchRunKeys($clock), $clock);
        $token = $store->claim('rr_0123456789abcdef', 1, ResearchUnitWorker::LEASE_MS);
        self::assertIsString($token);
        $lease = new ResearchLease($store, 'rr_0123456789abcdef', 1, $token, ResearchUnitWorker::LEASE_MS);
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')->with(
            self::anything(),
            7,
            self::callback(static function (array $options): bool {
                $again = $options['before_paid_retry'] ?? null;

                return 225 === ($options['timeout'] ?? null) && $again instanceof \Closure && true === $again();
            }),
        )->willReturn(['content' => 'kept', 'usage' => []]);
        $costs = $this->createStub(CostCalculationService::class);
        $costs->method('calculateCost')->willReturn(new CostResult('0.000000', '0.000000', '0.000000', '0.000000', [], 0));

        $reply = (new AiFacadeResearchModelCaller($ai, $this->models('test'), $costs))
            ->answer(7, 42, 'system', 'user', null, $lease);

        self::assertTrue($reply->called);
        self::assertSame('kept', $reply->text);
    }

    public function testALostLeaseDoesNotCallTheModel(): void
    {
        $clock = new MockClock('2026-10-29T12:00:00+00:00');
        $store = new ResearchStateStore(new MemoryResearchRunKeys($clock), $clock);
        $token = $store->claim('rr_0123456789abcdef', 1, ResearchUnitWorker::LEASE_MS);
        self::assertIsString($token);
        $clock->sleep(intdiv(ResearchUnitWorker::LEASE_MS, 1000) + 1);
        $lease = new ResearchLease($store, 'rr_0123456789abcdef', 1, $token, ResearchUnitWorker::LEASE_MS);
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');

        $reply = (new AiFacadeResearchModelCaller($ai, $this->models('test'), $this->createStub(CostCalculationService::class)))
            ->answer(7, 42, 'system', 'user', null, $lease);

        self::assertFalse($reply->called);
    }

    private function models(?string $provider): ModelConfigService
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getProviderForModel')->willReturn($provider);
        $models->method('getModelName')->willReturn('model');

        return $models;
    }
}
