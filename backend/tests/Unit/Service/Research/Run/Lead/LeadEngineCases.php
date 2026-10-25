<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run\Lead;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Lead\FixedLeadCatalog;
use App\Service\Research\Run\Lead\FixedLeadSettings;
use App\Service\Research\Run\Lead\LeadBench;
use App\Service\Research\Run\Lead\LeadConversation;
use App\Service\Research\Run\Lead\LeadMode;
use App\Service\Research\Run\Lead\LeadModeResolver;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\Stage\ResearchHit;
use App\Service\Research\Run\Stage\ResearchLeadTurn;
use App\Service\Research\Run\Stage\ResearchModelReply;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;

/**
 * Twenty scripted turns through the tool-mode engine: tools, budgets,
 * refusals, clearing and resume. Every model is a test double; the
 * provider check is `app:research:conformance`.
 */
final class LeadEngineCases
{
    public const CASES = 20;

    /**
     * @return array<string, ?string> case name to a failure, or null when it passed
     */
    public function results(): array
    {
        return [
            'search spends one search' => $this->searchSpends(),
            'a spent search budget runs nothing' => $this->searchBudget(),
            'a repeated query is refused' => $this->repeatedQuery(),
            'an unknown point is refused' => $this->unknownPoint(),
            'file search is refused' => $this->filesScope(),
            'a read returns cards and not the page' => $this->readCards(),
            'a spent read budget runs nothing' => $this->readBudget(),
            'an unknown source is refused' => $this->unknownSource(),
            'reread of an unread source is refused' => $this->rereadUnread(),
            'reread asks the sharper question' => $this->rereadQuestion(),
            'finish drops the other calls' => $this->finishDropsSearch(),
            'a turn with no tool gets one reminder' => $this->reminderThenStop(),
            'two searches in one turn both run' => $this->twoSearches(),
            'the search past the budget is refused' => $this->secondSearchBudget(),
            'clearing keeps the tool call and the latest result' => $this->clearingKeepsCalls(),
            'a resumed run does not repeat the search' => $this->resumedTurn(),
            'asking the person is refused' => $this->askUser(),
            'an unlisted model stays in round mode' => $this->unlistedModel(),
            'a listed model uses tools' => $this->listedModel(),
            'rounds wins over a listed model' => $this->roundsWins(),
        ];
    }

    private function searchSpends(): ?string
    {
        $bench = LeadBench::open();
        $bench->search->hits['warranty terms'] = [$this->hit(str_repeat('A', 200), str_repeat('B', 400))];
        $bench->lead->script[] = $this->turn($this->searchCall('c1', 'warranty terms'));
        if ('round' !== $bench->stage->run($bench->ctx)->next) {
            return 'the turn did not queue another turn';
        }
        if (['warranty terms'] !== $bench->search->queries) {
            return 'the search did not run once';
        }
        $saved = $bench->ctx->state->conversation->toArray()['turns'][0]['results'][0]['content'] ?? '';
        $body = $this->body(is_string($saved) ? $saved : '');
        $title = $body['results'][0]['title'] ?? '';
        $snippet = $body['results'][0]['snippet'] ?? '';
        if (!is_string($title) || mb_strlen($title) > 120 || !is_string($snippet) || mb_strlen($snippet) > 300) {
            return 'a result was longer than the lead may see';
        }
        if (!$this->hasEvent($bench, 'search', ['point', 'query', 'scope', 'results'])) {
            return 'the search event does not match round mode';
        }
        if (!$this->hasEvent($bench, 'round', ['n', 'max', 'next'])) {
            return 'the round event does not match round mode';
        }

        return null;
    }

    private function searchBudget(): ?string
    {
        $bench = LeadBench::open();
        $bench->ctx->state->budget->spend('searches', 10);
        $bench->search->hits['warranty'] = [$this->hit('Warranty', 'Two years')];
        $json = $bench->tools->execute($this->searchCall('c1', 'warranty'), $bench->ctx)->json;
        if ('budget_used' !== ($this->body($json)['error'] ?? null) || [] !== $bench->search->queries) {
            return 'the search ran with no budget left';
        }

        return null;
    }

    private function repeatedQuery(): ?string
    {
        $bench = LeadBench::open();
        $bench->ctx->state->ledger->addQuery('p1', 'Warranty Terms', 'web', 1);
        $json = $bench->tools->execute($this->searchCall('c1', 'warranty terms'), $bench->ctx)->json;
        if ('repeated_query' !== ($this->body($json)['error'] ?? null) || [] !== $bench->search->queries) {
            return 'the repeated query ran';
        }

        return null;
    }

    private function unknownPoint(): ?string
    {
        $bench = LeadBench::open();
        $call = new ToolCall('c1', 'search', ['point' => 'p9', 'query' => 'warranty', 'language' => 'en', 'scope' => 'web']);
        if ('unknown_id' !== ($this->body($bench->tools->execute($call, $bench->ctx)->json)['error'] ?? null)) {
            return 'an unknown point was searched';
        }

        return null;
    }

    private function filesScope(): ?string
    {
        $bench = LeadBench::open();
        $call = new ToolCall('c1', 'search', ['point' => 'p1', 'query' => 'warranty', 'language' => 'en', 'scope' => 'files']);
        $json = $bench->tools->execute($call, $bench->ctx)->json;
        if ('not_allowed' !== ($this->body($json)['error'] ?? null) || [] !== $bench->search->queries) {
            return 'a file search ran';
        }

        return null;
    }

    private function readCards(): ?string
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->reader->script[] = $this->evidence();
        $json = $bench->tools->execute(new ToolCall('r1', 'read_sources', ['ids' => [$id], 'point' => 'p1']), $bench->ctx)->json;
        if (str_contains($json, 'SECRET PAGE BODY') || [] === ($this->body($json)['cards'] ?? [])) {
            return 'the page leaked or no card was returned';
        }

        return null;
    }

    private function readBudget(): ?string
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->ctx->state->budget->spend('sources', 15);
        $json = $bench->tools->execute(new ToolCall('r1', 'read_sources', ['ids' => [$id], 'point' => 'p1']), $bench->ctx)->json;
        if ('budget_used' !== ($this->body($json)['error'] ?? null) || 0 !== $bench->pages->opened) {
            return 'a source was opened with no read budget';
        }

        return null;
    }

    private function unknownSource(): ?string
    {
        $bench = LeadBench::open();
        $json = $bench->tools->execute(new ToolCall('r1', 'read_sources', ['ids' => ['s9'], 'point' => 'p1']), $bench->ctx)->json;
        if ('unknown_id' !== ($this->body($json)['error'] ?? null)) {
            return 'an unknown source was read';
        }

        return null;
    }

    private function rereadUnread(): ?string
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $json = $bench->tools->execute(new ToolCall('r1', 'reread', ['source' => $id, 'question' => 'Net or gross?']), $bench->ctx)->json;
        if ('not_allowed' !== ($this->body($json)['error'] ?? null) || 0 !== $bench->pages->opened) {
            return 'an unread source was read again';
        }

        return null;
    }

    private function rereadQuestion(): ?string
    {
        $bench = LeadBench::open();
        $id = $bench->pricePage('https://maker.example/price');
        $bench->ctx->state->ledger->markSource($id, Source::READ);
        $bench->reader->script[] = $this->evidence();
        $json = $bench->tools->execute(new ToolCall('r1', 'reread', ['source' => $id, 'question' => 'Is 12.40 net or gross?']), $bench->ctx)->json;
        $asked = $bench->reader->users[0] ?? '';
        if (!str_contains($asked, 'net or gross') || str_contains($json, 'SECRET PAGE BODY')) {
            return 'the sharper question was not asked, or the page leaked';
        }

        return null;
    }

    private function finishDropsSearch(): ?string
    {
        $bench = LeadBench::open();
        $bench->search->hits['warranty'] = [$this->hit('Warranty', 'Two years')];
        $bench->lead->script[] = $this->turn(
            new ToolCall('f', 'finish', ['reason' => 'covered', 'points' => [['id' => 'p1', 'status' => 'answered', 'note' => 'Agreed.']]]),
            $this->searchCall('s', 'warranty'),
        );
        if ('write' !== $bench->stage->run($bench->ctx)->next || [] !== $bench->search->queries) {
            return 'finish still searched';
        }
        if ('open' !== $bench->ctx->state->ledger->points()[0]->status) {
            return 'a point with no finding was marked answered';
        }

        return null;
    }

    private function reminderThenStop(): ?string
    {
        $bench = LeadBench::open();
        $bench->lead->script[] = new ResearchLeadTurn(true, true, 'thinking', [], 'test:lead', null);
        $bench->lead->script[] = new ResearchLeadTurn(true, true, 'still thinking', [], 'test:lead', null);
        $first = $bench->stage->run($bench->ctx);
        if ('round' !== $first->next || !$bench->ctx->state->conversation->wasReminded()) {
            return 'the empty turn did not remind the lead';
        }
        $second = $bench->stage->run($bench->ctx);
        $prompt = $bench->lead->seen[1][0]['content'] ?? '';
        if ('write' !== $second->next || !is_string($prompt) || !str_contains($prompt, 'Unit price')) {
            return 'the second empty turn did not go to the report with the plan';
        }

        return null;
    }

    private function twoSearches(): ?string
    {
        $bench = LeadBench::open();
        $bench->search->hits['alpha'] = [$this->hit('Alpha', 'One')];
        $bench->search->hits['beta'] = [$this->hit('Beta', 'Two', 'https://example.com/beta')];
        $bench->lead->script[] = $this->turn($this->searchCall('a', 'alpha'), $this->searchCall('b', 'beta'));
        if ('round' !== $bench->stage->run($bench->ctx)->next || ['alpha', 'beta'] !== $bench->search->queries) {
            return 'parallel searches did not both run';
        }

        return null;
    }

    private function secondSearchBudget(): ?string
    {
        $bench = LeadBench::open();
        $bench->ctx->state->budget->spend('searches', 9);
        $bench->search->hits['alpha'] = [$this->hit('Alpha', 'One')];
        $bench->search->hits['beta'] = [$this->hit('Beta', 'Two', 'https://example.com/beta')];
        $bench->lead->script[] = $this->turn($this->searchCall('a', 'alpha'), $this->searchCall('b', 'beta'));
        $bench->stage->run($bench->ctx);
        $second = $bench->ctx->state->conversation->toArray()['turns'][0]['results'][1]['content'] ?? '';
        if (['alpha'] !== $bench->search->queries || !is_string($second) || !str_contains($second, 'budget_used')) {
            return 'the second search ran after the budget was spent';
        }

        return null;
    }

    private function clearingKeepsCalls(): ?string
    {
        $talk = new LeadConversation();
        $long = str_repeat('x', 800);
        $call = static fn (string $id): array => ['id' => $id, 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']];
        $talk->append('first', [$call('c1')], [['id' => 'c1', 'name' => 'search', 'content' => $long]]);
        $talk->append('second', [$call('c2')], [['id' => 'c2', 'name' => 'search', 'content' => $long.' fresh']]);
        $messages = $talk->messagesFor(10, 'LEDGER');
        $tools = array_values(array_filter($messages, static fn (array $message): bool => 'tool' === ($message['role'] ?? null)));
        $calls = $messages[0]['tool_calls'][0]['function']['name'] ?? null;
        $stored = $talk->toArray()['turns'][0]['results'][0]['content'] ?? '';
        if ('LEDGER' !== ($tools[0]['content'] ?? null) || !str_contains((string) ($tools[1]['content'] ?? ''), 'fresh')) {
            return 'older tool results were not replaced, or the latest was';
        }
        if ('search' !== $calls || $stored !== $long) {
            return 'the tool call or the stored result was dropped';
        }

        return null;
    }

    private function resumedTurn(): ?string
    {
        $bench = LeadBench::open();
        $bench->search->hits['warranty'] = [$this->hit('Warranty', 'Two years')];
        $bench->lead->script[] = $this->turn($this->searchCall('c1', 'warranty'));
        $bench->lead->script[] = $this->turn(new ToolCall('f', 'finish', [
            'reason' => 'budget',
            'points' => [['id' => 'p1', 'status' => 'thin', 'note' => 'One source.']],
        ]));
        $bench->stage->run($bench->ctx);
        $restored = ResearchRunState::decode($bench->ctx->state->encode());
        if (!$restored instanceof ResearchRunState) {
            return 'the turn could not be restored';
        }
        $restored->budget->at($bench->ctx->today);
        $again = new StageContext(
            $restored,
            $bench->ctx->roles,
            new ResearchEventLog($bench->keys),
            $bench->ctx->today,
            new ResearchStop(),
        );
        $bench->stage->run($again);
        $second = $bench->lead->seen[1] ?? [];
        $roles = array_column($second, 'role');
        if (['warranty'] !== $bench->search->queries || !in_array('tool', $roles, true)) {
            return 'the resumed run repeated the search or forgot the tool result';
        }

        return null;
    }

    private function askUser(): ?string
    {
        $bench = LeadBench::open();
        $call = new ToolCall('a', 'ask_user', ['question' => 'Which shop?', 'options' => ['Alpha', 'Beta']]);
        if ('not_allowed' !== ($this->body($bench->tools->execute($call, $bench->ctx)->json)['error'] ?? null)) {
            return 'the question was accepted before a run can wait';
        }

        return null;
    }

    private function unlistedModel(): ?string
    {
        $mode = $this->resolver('auto', ['anthropic:claude:chat'], 'other:model:chat')->resolve(3, ResearchDepth::Standard);
        if (LeadMode::Rounds !== $mode) {
            return 'a model that is not listed used tools';
        }

        return null;
    }

    private function listedModel(): ?string
    {
        $resolver = $this->resolver('auto', ['anthropic:claude:chat'], 'anthropic:claude:chat');
        if (LeadMode::Tools !== $resolver->resolve(3, ResearchDepth::Standard)) {
            return 'a listed model stayed in round mode';
        }
        if (LeadMode::Rounds !== $resolver->resolve(3, ResearchDepth::Quick)) {
            return 'a quick run left round mode';
        }

        return null;
    }

    private function roundsWins(): ?string
    {
        $mode = $this->resolver('rounds', ['anthropic:claude:chat'], 'anthropic:claude:chat')->resolve(3, ResearchDepth::Standard);
        if (LeadMode::Rounds !== $mode) {
            return 'an explicit round setting used tools';
        }

        return null;
    }

    /**
     * @param list<string> $models
     */
    private function resolver(string $mode, array $models, string $key): LeadModeResolver
    {
        return new LeadModeResolver(new FixedLeadSettings($mode, $models), new FixedLeadCatalog($key));
    }

    private function searchCall(string $id, string $query): ToolCall
    {
        return new ToolCall($id, 'search', [
            'point' => 'p1',
            'query' => $query,
            'language' => 'en',
            'scope' => 'web',
        ]);
    }

    private function turn(ToolCall ...$calls): ResearchLeadTurn
    {
        return new ResearchLeadTurn(true, true, '', array_values($calls), 'test:lead', null);
    }

    private function hit(string $title, string $snippet, string $url = 'https://example.com/warranty'): ResearchHit
    {
        $hit = ResearchHit::from($url, $title, $snippet, '2026-01-01');
        if (!$hit instanceof ResearchHit) {
            throw new \RuntimeException('The conformance fixture needs a valid address.');
        }

        return $hit;
    }

    private function evidence(): ResearchModelReply
    {
        $data = [
            'source_id' => 's1',
            'useful' => true,
            'published' => null,
            'kind' => 'vendor',
            'findings' => [[
                'point' => 'p1',
                'fact' => 'The unit price is 12.40 EUR.',
                'passages' => ['§1'],
                'quote' => ['text' => 'The unit price is 12.40 EUR.', 'passage' => '§1'],
                'confidence' => 'high',
            ]],
            'leads' => [],
            'flags' => [],
        ];

        return new ResearchModelReply(true, true, $data, (string) json_encode($data), 'test:reader', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param list<string> $keys
     */
    private function hasEvent(LeadBench $bench, string $name, array $keys): bool
    {
        foreach ($bench->keys->events as $body) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || $name !== ($decoded['event'] ?? null) || !is_array($decoded['data'] ?? null)) {
                continue;
            }
            foreach ($keys as $key) {
                if (!array_key_exists($key, $decoded['data'])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
