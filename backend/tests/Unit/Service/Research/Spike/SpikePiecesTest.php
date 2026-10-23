<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Spike;

use App\AI\Provider\TestProvider;
use App\Service\Research\Spike\Finding;
use App\Service\Research\Spike\LeadUrlFilter;
use App\Service\Research\Spike\PassageCheck;
use App\Service\Research\Spike\PassageSplitter;
use App\Service\Research\Spike\RoundModeLoop;
use App\Service\Research\Spike\SpikeToolCall;
use App\Service\Research\Spike\ToolBudget;
use App\Service\Research\Spike\ToolModeLoop;
use PHPUnit\Framework\TestCase;

final class SpikePiecesTest extends TestCase
{
    public function testTheSameTextKeepsTheSamePassageIds(): void
    {
        $text = "First paragraph.\n\n- one item\n\n| col | val |\n| --- | --- |\n\n".str_repeat('Sentence one. ', 50);

        $splitter = new PassageSplitter();
        $first = $splitter->split($text);
        $second = $splitter->split($text);

        self::assertSame(
            array_map(static fn ($passage): string => $passage->id.' '.$passage->text, $first),
            array_map(static fn ($passage): string => $passage->id.' '.$passage->text, $second),
        );
        self::assertSame('§1', $first[0]->id);
        self::assertSame('First paragraph.', $first[0]->text);
        self::assertSame('- one item', $first[1]->text);
        self::assertSame('| col | val |', $first[2]->text);
        self::assertGreaterThan(600, mb_strlen(str_repeat('Sentence one. ', 50)));
        self::assertGreaterThan(3, count($first));
    }

    public function testAMissingPassageDropsTheFindingAndAForeignQuoteIsRemoved(): void
    {
        $passages = (new PassageSplitter())->split("The price is 1.500 euro.\n\nThe warranty is two years.");
        $check = new PassageCheck();

        $result = $check->apply([
            new Finding('missing', ['§9'], '1.500'),
            new Finding('price', ['§1'], '“1,500 euro”'),
            new Finding('wrong quote', ['§1'], 'two years'),
        ], $passages);

        self::assertSame(1, $result['dropped']);
        self::assertCount(2, $result['kept']);
        self::assertSame('“1,500 euro”', $result['kept'][0]->quote);
        self::assertNull($result['kept'][1]->quote);
        self::assertSame(['§1'], $result['kept'][1]->passageIds);
    }

    public function testAUrlOutsideThePageLinkListIsDropped(): void
    {
        $kept = (new LeadUrlFilter())->keep(
            [
                'https://evil.example/phish',
                'https://Example.com/docs/',
                'https://example.com/docs',
            ],
            ['https://example.com/docs', 'https://example.com/other'],
        );

        self::assertSame(['https://example.com/docs'], $kept);
    }

    public function testASearchPastTheBudgetReturnsBudgetUsed(): void
    {
        $result = (new ToolModeLoop())->run(
            new TestProvider(),
            'TOOLTEST:search:{"query":"bayern"}',
            new ToolBudget(0, 15),
        );

        self::assertSame(0, $result['searches']);
        self::assertSame('no_progress', $result['stopped']);
        self::assertIsString($result['toolResult']);
        self::assertStringContainsString('"error":"budget_used"', $result['toolResult']);
    }

    public function testFinishEndsToolModeWithoutASearch(): void
    {
        $result = (new ToolModeLoop())->run(
            new TestProvider(),
            'TOOLTEST:finish:{"reason":"covered","points":[]}',
            new ToolBudget(10, 15),
        );

        self::assertSame('finish', $result['stopped']);
        self::assertSame(0, $result['searches']);
    }

    public function testRoundModeIgnoresActionsWhenFinishIsSet(): void
    {
        $result = (new RoundModeLoop())->run(
            new TestProvider(),
            '{"points":[],"actions":[{"type":"search","point":"p1","query":"x","language":"de","scope":"web"}],"finish":"covered"}',
            new ToolBudget(10, 15),
        );

        self::assertSame('covered', $result['stopped']);
        self::assertSame(0, $result['searches']);
    }

    public function testRoundModeDropsASearchPastTheBudget(): void
    {
        $result = (new RoundModeLoop())->run(
            new TestProvider(),
            '{"points":[],"actions":[{"type":"search","point":"p1","query":"x","language":"de","scope":"web"}],"finish":null}',
            new ToolBudget(0, 15),
        );

        self::assertSame('continue', $result['stopped']);
        self::assertSame(0, $result['searches']);
    }

    public function testAnEmptyToolCallIsSentBackAsAnObject(): void
    {
        $message = (new SpikeToolCall('call_1', 'finish', []))->toMessage();

        self::assertSame('{"ok":true}', $message['function']['arguments']);
    }
}
