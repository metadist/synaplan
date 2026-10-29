<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\Service\AiFacade;
use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\AI\ToolCalling\ToolDefinition;
use App\DTO\CostResult;
use App\Service\ModelConfigService;
use App\Service\Research\Run\Stage\AiFacadeResearchLeadCaller;
use App\Service\Usage\CostCalculationService;
use PHPUnit\Framework\TestCase;

final class AiFacadeResearchLeadCallerTest extends TestCase
{
    public function testTheLeadsWordsStreamAndItsToolCallsSurviveAStreamWithoutAssembledCalls(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');
        $ai->method('chatStream')->willReturnCallback(static function (array $messages, callable $callback): array {
            $callback(['type' => 'content', 'content' => 'Two shops agree. ']);
            $callback(['type' => 'content', 'content' => 'I read the maker next.']);
            $callback(['type' => 'tool_call_delta', 'index' => 0, 'id' => 'c1', 'name' => 'read', 'arguments' => '{"source":']);
            $callback(['type' => 'tool_call_delta', 'index' => 0, 'arguments' => '"s1"}']);

            return ['content' => 'Two shops agree. I read the maker next.', 'usage' => []];
        });
        $streamed = [];

        $turn = $this->caller($ai)->turn(7, 42, [['role' => 'user', 'content' => 'Price?']], [$this->tool()], static function (string $text) use (&$streamed): void {
            $streamed[] = $text;
        });

        self::assertTrue($turn->ok);
        self::assertSame(['Two shops agree. ', 'I read the maker next.'], $streamed);
        self::assertCount(1, $turn->calls);
        self::assertSame('read', $turn->calls[0]->name);
        self::assertSame(['source' => 's1'], $turn->calls[0]->arguments);
    }

    public function testWithoutAListenerTheLeadIsAskedInOnePiece(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chatStream');
        $ai->expects(self::once())->method('chat')->willReturn(['content' => 'Done.', 'usage' => []]);

        $turn = $this->caller($ai)->turn(7, 42, [['role' => 'user', 'content' => 'Price?']], [$this->tool()]);

        self::assertSame('Done.', $turn->text);
    }

    private function caller(AiFacade $ai): AiFacadeResearchLeadCaller
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getProviderForModel')->willReturn('anthropic');
        $models->method('getModelName')->willReturn('model');
        $costs = $this->createStub(CostCalculationService::class);
        $costs->method('calculateCost')->willReturn(new CostResult('0', '0', '0', '0', [], 0));

        return new AiFacadeResearchLeadCaller($ai, $models, $costs, new ToolCallingTranslator(new ToolCallingCapability()), new ToolCallParser());
    }

    private function tool(): ToolDefinition
    {
        return new ToolDefinition('read', 'Read a source.', ['type' => 'object', 'properties' => ['source' => ['type' => 'string']]]);
    }
}
