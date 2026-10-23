<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Spike;

use App\Service\Research\Spike\JsonSlice;
use App\Service\Research\Spike\LeadUrlFilter;
use App\Service\Research\Spike\PageBody;
use App\Service\Research\Spike\PassageCheck;
use App\Service\Research\Spike\PassageSplitter;
use App\Service\Research\Spike\PromptBook;
use App\Service\Research\Spike\SearchHit;
use App\Service\Research\Spike\SpikeChat;
use App\Service\Research\Spike\SpikeEngine;
use App\Service\Research\Spike\SpikeLimits;
use App\Service\Research\Spike\SpikeReader;
use App\Service\Research\Spike\SpikeSources;
use App\Service\Research\Spike\SpikeToolCall;
use App\Service\Research\Spike\SpikeTurn;
use PHPUnit\Framework\TestCase;

final class SpikeEngineTest extends TestCase
{
    public function testToolModeKeepsACitedFactAndDropsAMissingPassage(): void
    {
        $chat = new ScriptedSpikeChat([
            $this->jsonTurn('{"goal":"Act number","language":"en","output":"answer"}'),
            $this->jsonTurn('{"points":[{"id":"p1","title":"Act number","queries":["eu ai act"]}],"outline":["Summary"]}'),
            new SpikeTurn('', [new SpikeToolCall('c1', 'search', ['query' => 'eu ai act'])]),
            new SpikeTurn('', [new SpikeToolCall('c2', 'read_sources', ['ids' => ['s1']])]),
            $this->jsonTurn('{"findings":[{"point":"p1","fact":"The Act is Regulation (EU) 2024/1689.","passages":["§1"],"quote":"Regulation (EU) 2024/1689"},{"point":"p1","fact":"invented price","passages":["§9"],"quote":"nope"}],"leads":[{"link":"L1","point":"p1"},{"link":"https://evil.example/phish","point":"p1"}]}'),
            new SpikeTurn('', [new SpikeToolCall('c3', 'finish', ['reason' => 'covered'])]),
            $this->jsonTurn('Regulation (EU) 2024/1689.'),
        ]);
        $sources = new ScriptedSpikeSources();

        $answer = $this->engine($chat, $sources)->answer('What is the act number?', new SpikeLimits(2, 2, 6), 'tools', 1);

        self::assertSame('Regulation (EU) 2024/1689.', $answer->report);
        self::assertSame(1, $answer->searches);
        self::assertSame(1, $answer->reads);
        self::assertSame(1, $answer->keptFindings);
        self::assertSame(1, $answer->droppedFindings);
        self::assertSame(['https://eur-lex.europa.eu/ai-act'], $sources->reads);
        $write = $chat->seen[array_key_last($chat->seen)][0]['content'] ?? '';
        self::assertStringContainsString('2024/1689', $write);
        self::assertStringNotContainsString('invented price', $write);
    }

    public function testASearchPastTheBudgetDoesNotSearch(): void
    {
        $chat = new ScriptedSpikeChat([
            $this->jsonTurn('{"goal":"Act number","language":"en"}'),
            $this->jsonTurn('{"points":[{"id":"p1","title":"Act number","queries":["eu ai act"]}]}'),
            new SpikeTurn('', [new SpikeToolCall('c1', 'search', ['query' => 'eu ai act'])]),
            new SpikeTurn('', [new SpikeToolCall('c2', 'finish', [])]),
            $this->jsonTurn('No search.'),
        ]);
        $sources = new ScriptedSpikeSources();

        $answer = $this->engine($chat, $sources)->answer('What is the act number?', new SpikeLimits(0, 2, 4), 'tools', 1);

        self::assertSame(0, $answer->searches);
        self::assertSame([], $sources->queries);
        self::assertTrue($this->saw($chat, 'budget_used'));
    }

    public function testRoundModeIgnoresActionsWhenItFinishes(): void
    {
        $chat = new ScriptedSpikeChat([
            $this->jsonTurn('{"goal":"Act number","language":"en"}'),
            $this->jsonTurn('{"points":[{"id":"p1","title":"Act number","queries":["eu ai act"]}]}'),
            $this->jsonTurn('{"points":[],"actions":[{"type":"search","query":"eu ai act"}],"finish":"covered"}'),
            $this->jsonTurn('{"points":[],"actions":[],"finish":"covered"}'),
            $this->jsonTurn('Done.'),
        ]);
        $sources = new ScriptedSpikeSources();

        $answer = $this->engine($chat, $sources)->answer('What is the act number?', new SpikeLimits(2, 2, 4), 'rounds', 1);

        self::assertSame('Done.', $answer->report);
        self::assertSame(0, $answer->searches);
        self::assertSame([], $sources->queries);
    }

    private function engine(SpikeChat $chat, SpikeSources $sources): SpikeEngine
    {
        $prompts = new PromptBook();
        $json = new JsonSlice();
        $reader = new SpikeReader($chat, new PassageSplitter(), new PassageCheck(), new LeadUrlFilter(), $json, $prompts);

        return new SpikeEngine($chat, $sources, $reader, $json, $prompts);
    }

    private function jsonTurn(string $content): SpikeTurn
    {
        return new SpikeTurn($content, []);
    }

    private function saw(ScriptedSpikeChat $chat, string $needle): bool
    {
        foreach ($chat->seen as $messages) {
            foreach ($messages as $message) {
                if (str_contains((string) ($message['content'] ?? ''), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}

final class ScriptedSpikeChat implements SpikeChat
{
    /** @var list<list<array<string, mixed>>> */
    public array $seen = [];

    /**
     * @param list<SpikeTurn> $turns
     */
    public function __construct(
        private array $turns,
    ) {
    }

    public function complete(array $messages, array $tools, int $userId): SpikeTurn
    {
        $this->seen[] = $messages;

        return array_shift($this->turns) ?? new SpikeTurn('{}', []);
    }
}

final class ScriptedSpikeSources implements SpikeSources
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> */
    public array $reads = [];

    public function search(string $query, int $limit, int $userId): array
    {
        $this->queries[] = $query;

        return [new SearchHit('https://eur-lex.europa.eu/ai-act', 'AI Act', 'Regulation')];
    }

    public function read(string $url): PageBody
    {
        $this->reads[] = $url;

        return new PageBody(
            'Regulation (EU) 2024/1689 is the EU Artificial Intelligence Act. See https://eur-lex.europa.eu/eli/reg/2024/1689',
            ['https://eur-lex.europa.eu/eli/reg/2024/1689'],
        );
    }
}
