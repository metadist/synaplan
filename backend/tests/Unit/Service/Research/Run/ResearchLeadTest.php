<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\ToolCalling\ToolCall;
use App\Command\ResearchLeadConformanceCommand;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Research\Run\Lead\LeadBench;
use App\Service\Research\Run\Lead\LeadConformance;
use App\Service\Research\Run\Lead\LeadConversation;
use App\Service\Research\Run\Lead\ModelLeadCatalog;
use App\Service\Research\Run\Lead\ScriptedLead;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\Stage\ResearchLeadTurn;
use App\Tests\Unit\Service\Research\Run\Lead\LeadEngineCases;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ResearchLeadTest extends TestCase
{
    public function testTheEngineCasesPass(): void
    {
        $results = (new LeadEngineCases())->results();

        self::assertCount(LeadEngineCases::CASES, $results);
        $failed = array_filter($results, static fn (?string $failure): bool => null !== $failure);
        self::assertSame([], $failed);
    }

    public function testTheConformanceSuiteFailsAModelThatNeverCallsATool(): void
    {
        $results = (new LeadConformance(new ScriptedLead()))->results(3, 7);

        self::assertCount(LeadConformance::TURNS, $results);
        self::assertNotContains(null, $results);
    }

    public function testTheCommandSendsNothingWithoutAnAccount(): void
    {
        $tester = new CommandTester($this->command(null));
        $tester->execute(['key' => 'anthropic:claude:chat']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was sent', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheCommandSendsNothingForAnUnknownModel(): void
    {
        $tester = new CommandTester($this->command(null));
        $tester->execute(['key' => 'nobody:no-model:chat', '--user' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was sent', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheCatalogKeyIsNormalisedLikeTheCatalog(): void
    {
        $model = (new Model())->setService('Anthropic')->setProviderId('Claude:Sonnet')->setTag('Chat');

        self::assertSame('anthropic:claude-sonnet:chat', $this->catalog($model)->key(5));
    }

    public function testAnUnreadableModelListStaysEmpty(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                ResearchConfig::LEAD_MODE => 'TOOLS',
                ResearchConfig::TOOL_MODE_MODELS => '{',
                default => null,
            },
        );
        $config = new ResearchConfig($repo, '');

        self::assertSame('tools', $config->leadMode());
        self::assertSame([], $config->toolModeModels());
    }

    public function testListedKeysAreCompared(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn('["Anthropic:Claude-Sonnet:Chat"]');

        self::assertSame(['anthropic:claude-sonnet:chat'], (new ResearchConfig($repo, ''))->toolModeModels());
    }

    public function testATurnWithoutToolsIsNotSentAsAnEmptyToolCallList(): void
    {
        $talk = new LeadConversation();
        $talk->append('', [], []);
        $talk->remind('Call a tool.');
        $talk->append('thinking', [], []);

        $messages = $talk->messagesFor(100000, 'LEDGER');

        self::assertSame([
            ['role' => 'user', 'content' => 'Call a tool.'],
            ['role' => 'assistant', 'content' => 'thinking'],
        ], $messages);
    }

    public function testTheReminderStaysAfterTheTurnItAnswered(): void
    {
        $call = ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']];
        $talk = new LeadConversation();
        $talk->append('nothing', [], []);
        $talk->remind('Call a tool.');
        $talk->append('', [$call], [['id' => 'c1', 'name' => 'search', 'content' => '{}']]);

        $restored = LeadConversation::fromArray($talk->toArray());
        $roles = array_column($restored->messagesFor(100000, 'LEDGER'), 'role');

        self::assertSame(['assistant', 'user', 'assistant', 'tool'], $roles);
    }

    public function testClearingSendsTheLedgerSummaryOnce(): void
    {
        $long = str_repeat('x', 800);
        $call = static fn (string $id): array => ['id' => $id, 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']];
        $talk = new LeadConversation();
        $talk->append('', [$call('a'), $call('b')], [
            ['id' => 'a', 'name' => 'search', 'content' => $long],
            ['id' => 'b', 'name' => 'search', 'content' => $long],
        ]);
        $talk->append('', [$call('c')], [['id' => 'c', 'name' => 'search', 'content' => $long]]);
        $talk->append('', [$call('d')], [['id' => 'd', 'name' => 'search', 'content' => 'fresh']]);

        $tools = array_values(array_filter($talk->messagesFor(10, 'LEDGER'), static fn (array $message): bool => 'tool' === $message['role']));
        $contents = array_column($tools, 'content');

        self::assertSame(1, count(array_keys($contents, 'LEDGER', true)));
        self::assertSame('LEDGER', $contents[2]);
        self::assertSame('fresh', $contents[3]);
        self::assertStringContainsString('cleared', (string) $contents[0]);
    }

    public function testAFailedRereadKeepsTheSourceRead(): void
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->ctx->state->ledger->markSource($id, Source::READ);
        $bench->pages->pages = [];

        $json = $bench->tools->execute(new ToolCall('r1', 'reread', ['source' => $id, 'question' => 'Net or gross?']), $bench->ctx)->json;

        self::assertStringContainsString('"blocked":[{"id":"'.$id.'"', $json);
        self::assertSame(Source::READ, $bench->ctx->state->ledger->source($id)?->status);
    }

    public function testTheLeadSeesLeanCardsMarkedAsData(): void
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->reader->script[] = LeadBench::evidence();

        $json = $bench->tools->execute(new ToolCall('r1', 'read_sources', ['ids' => [$id], 'point' => 'p1']), $bench->ctx)->json;
        $body = json_decode($json, true);
        $card = is_array($body) && is_array($body['cards'][0] ?? null) ? $body['cards'][0] : [];

        self::assertSame(['id', 'source', 'point', 'fact', 'quotes', 'confidence'], array_keys($card));
        self::assertSame(['The unit price is 12.40 EUR.'], $card['quotes']);
    }

    public function testAFinishBeforeAnyReadIsRefusedAndTheOtherCallsRun(): void
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->reader->script[] = LeadBench::evidence();
        $bench->lead->script[] = new ResearchLeadTurn(true, true, 'The snippets settle it.', [
            new ToolCall('f', 'finish', ['reason' => 'covered', 'points' => [['id' => 'p1', 'status' => 'answered', 'note' => 'From snippets.']]]),
            new ToolCall('r', 'read_sources', ['ids' => [$id], 'point' => 'p1']),
        ], 'test:lead', null);

        $next = $bench->stage->run($bench->ctx)->next;
        $results = $bench->ctx->state->conversation->toArray()['turns'][0]['results'] ?? [];

        self::assertSame('round', $next);
        self::assertStringContainsString('"error":"read_first"', (string) ($results[0]['content'] ?? ''));
        self::assertStringContainsString('"cards"', (string) ($results[1]['content'] ?? ''));
        self::assertSame(1, $bench->ctx->state->ledger->evidenceCount());
    }

    public function testAFinishAfterAReadThatFoundNothingGoesToTheReport(): void
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->pricePage('https://other.example/price');
        $bench->ctx->state->ledger->markSource($id, Source::READ);
        $bench->ctx->state->budget->spend('sources');
        $bench->lead->script[] = new ResearchLeadTurn(true, true, '', [
            new ToolCall('f', 'finish', ['reason' => 'nothing', 'points' => [['id' => 'p1', 'status' => 'open', 'note' => 'Not found.']]]),
        ], 'test:lead', null);

        self::assertSame('write', $bench->stage->run($bench->ctx)->next);
    }

    public function testAnUnknownToolIsNamed(): void
    {
        $bench = LeadBench::open();
        $json = $bench->tools->execute(new ToolCall('x', 'browse', []), $bench->ctx)->json;

        self::assertStringContainsString('"error":"unknown_tool"', $json);
    }

    private function command(?Model $model): ResearchLeadConformanceCommand
    {
        return new ResearchLeadConformanceCommand(new LeadConformance(new ScriptedLead()), $this->catalog($model));
    }

    private function catalog(?Model $model): ModelLeadCatalog
    {
        $repo = $this->createStub(ModelRepository::class);
        $repo->method('find')->willReturn($model);

        return new ModelLeadCatalog($repo);
    }
}
