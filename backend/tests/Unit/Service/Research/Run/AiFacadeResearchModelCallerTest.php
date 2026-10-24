<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\Schema\ResearchTriageSchema;
use App\DTO\CostResult;
use App\Service\ModelConfigService;
use App\Service\Research\Run\Stage\AiFacadeResearchModelCaller;
use App\Service\Usage\CostCalculationService;
use PHPUnit\Framework\TestCase;

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

    private function models(?string $provider): ModelConfigService
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getProviderForModel')->willReturn($provider);
        $models->method('getModelName')->willReturn('model');

        return $models;
    }
}
