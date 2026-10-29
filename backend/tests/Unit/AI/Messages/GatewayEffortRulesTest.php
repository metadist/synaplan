<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\GatewayEffortRules;
use PHPUnit\Framework\TestCase;

final class GatewayEffortRulesTest extends TestCase
{
    public function testTheParagraphBecomesTheOnlySystemBlockWhenThereIsNone(): void
    {
        $body = GatewayEffortRules::apply(['messages' => []]);

        self::assertSame([['type' => 'text', 'text' => GatewayEffortRules::PARAGRAPH]], $body['system']);
    }

    public function testAStringSystemPromptIsKeptFirst(): void
    {
        $body = GatewayEffortRules::apply(['system' => 'You are terse.']);

        self::assertSame('You are terse.', $body['system'][0]['text']);
        self::assertSame(GatewayEffortRules::PARAGRAPH, $body['system'][1]['text']);
    }

    public function testTheParagraphIsSaidOnce(): void
    {
        $once = GatewayEffortRules::apply(['system' => [['type' => 'text', 'text' => 'Client rules']]]);
        $twice = GatewayEffortRules::apply($once);

        self::assertSame($once, $twice);
        self::assertCount(2, $twice['system']);
    }

    public function testTheParagraphNamesWhenNotToSearch(): void
    {
        self::assertStringContainsString('One fact: one search', GatewayEffortRules::PARAGRAPH);
        self::assertStringContainsString('Never search for greetings, code, writing, translation', GatewayEffortRules::PARAGRAPH);
    }
}
