<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ConnectedToolCatalog;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class ConnectedToolCatalogTest extends TestCase
{
    public function testABareNameOpensThatTool(): void
    {
        $state = $this->state();

        $text = ConnectedToolCatalog::run($this->connected(2), $state, ['use' => 'tool_1']);

        self::assertSame(['tool_1'], $state->opened);
        self::assertSame('Open now: tool_1. Call them directly.', $text);
    }

    public function testATenthToolIsNamedAsNotOpened(): void
    {
        $state = $this->state();
        $names = array_keys($this->connected(10));

        $text = ConnectedToolCatalog::run($this->connected(10), $state, ['use' => [...$names, 'tool_1']]);

        self::assertSame(\array_slice($names, 0, ConnectedToolCatalog::MAX_OPEN), $state->opened);
        self::assertStringContainsString('Not opened, at most 8 tools can be open in one run: tool_9, tool_10.', $text);
        self::assertStringNotContainsString('Not in the list', $text);
    }

    public function testAToolAlreadyOpenStaysOpenWhenTheRunIsFull(): void
    {
        $state = $this->state();
        $state->opened = array_keys($this->connected(8));

        $text = ConnectedToolCatalog::run($this->connected(10), $state, ['use' => ['tool_3', 'nope']]);

        self::assertCount(ConnectedToolCatalog::MAX_OPEN, $state->opened);
        self::assertSame("Open now: tool_3. Call them directly.\nNot in the list: nope.", $text);
    }

    public function testNoArgumentsListTheToolsWithCappedDescriptions(): void
    {
        $text = ConnectedToolCatalog::run($this->connected(61), $this->state(), []);

        self::assertStringContainsString('- tool_1: Does thing 1', $text);
        self::assertStringNotContainsString('tool_61', $text);
        self::assertStringEndsWith('(1 more not listed)', $text);
    }

    /**
     * @return array<string, ToolDescriptor>
     */
    private function connected(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; ++$i) {
            $out['tool_'.$i] = new ToolDescriptor('tool_'.$i, 'Tool '.$i, "Does thing {$i}\n".str_repeat('x', 300), ['type' => 'object'], SideEffect::Read, ToolSource::Mcp, 7);
        }

        return $out;
    }

    private function state(): ChatRunState
    {
        return new ChatRunState('run_1', 7, 11, null, null, null, 'en', '', [], 1);
    }
}
