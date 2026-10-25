<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Stage\ResearchLeadCaller;

/**
 * Ten scenarios, each played twice, through the live lead caller: the model
 * gets the run's prompt, history and tools, and the engine executes its calls
 * on scripted search, pages and reader. Only the lead model is called. A model
 * that passes all twenty turns may go on `RESEARCH.TOOL_MODE_MODELS`.
 */
final readonly class LeadConformance
{
    public const SCENARIOS = 10;
    public const RUNS = 2;
    public const TURNS = self::SCENARIOS * self::RUNS;

    private const PRICE_URL = 'https://maker.example/price-list';
    private const SHOP_URL = 'https://shop.example/unit';
    private const REFUSED = ['not_allowed', 'unknown_id', 'unknown_tool'];

    public function __construct(private ResearchLeadCaller $caller)
    {
    }

    /**
     * @param (callable(string, ?string): void)|null $onTurn called after every turn
     *
     * @return array<string, ?string> turn name to a failure, or null when it passed
     */
    public function results(int $modelId, int $userId, ?callable $onTurn = null): array
    {
        $scenarios = [
            'opens with a search' => $this->opensWithSearch(...),
            'searches two points in one turn' => $this->searchesBothPoints(...),
            'reads the source it found' => $this->readsFoundSource(...),
            'does not repeat a refused query' => $this->avoidsRepeatedQuery(...),
            'finishes when the budget is used' => $this->finishesOnBudget(...),
            'finishes when two sources agree' => $this->finishesWhenCovered(...),
            'stays valid after a long result' => $this->longResult(...),
            'reads when no search is left' => $this->readsWhenSearchesUsed(...),
            'moves on to the open point' => $this->movesToOpenPoint(...),
            'finish names every point honestly' => $this->finishNamesEveryPoint(...),
        ];
        $results = [];
        foreach ($scenarios as $name => $scenario) {
            for ($run = 1; $run <= self::RUNS; ++$run) {
                $label = $name.' (run '.$run.')';
                $bench = LeadBench::open($this->caller, $modelId, $userId);
                $failure = $scenario($bench);
                $results[$label] = null === $failure ? null : $failure.$this->sent($bench);
                if (null !== $onTurn) {
                    $onTurn($label, $results[$label]);
                }
            }
        }

        return $results;
    }

    private function opensWithSearch(LeadBench $bench): ?string
    {
        $this->offer($bench, self::PRICE_URL);
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }

        return $this->has($calls, 'search', 'p1') ? null : 'the first turn did not search for p1';
    }

    private function searchesBothPoints(LeadBench $bench): ?string
    {
        $bench->ctx->state->ledger->addPoint('Warranty period of the Maker X100', 'Needed.');
        $this->offer($bench, self::PRICE_URL);
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }

        return $this->has($calls, 'search', 'p1') && $this->has($calls, 'search', 'p2')
            ? null
            : 'the first turn did not search both independent points';
    }

    private function readsFoundSource(LeadBench $bench): ?string
    {
        $id = $this->found($bench);
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }

        return $this->reads($calls, $id) ? null : 'the model did not read the source it found';
    }

    private function avoidsRepeatedQuery(LeadBench $bench): ?string
    {
        $this->found($bench);
        $this->prior($bench, $this->search($bench, 'unit price'));
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }
        foreach ($calls as $call) {
            if ('repeated_query' === $call['error']) {
                return 'the model sent a query that was already refused';
            }
        }

        return null;
    }

    private function finishesOnBudget(LeadBench $bench): ?string
    {
        $this->found($bench);
        $this->spendAll($bench);
        $this->prior($bench, $this->search($bench, 'unit price 2026'));
        $points = $this->finishAfterBudget($bench);
        if (is_string($points)) {
            return $points;
        }

        return isset($points['p1']) ? null : 'finish did not give p1 a status';
    }

    /**
     * Plays until finish. One more turn is allowed when every call of the
     * first was refused for the budget: that costs nothing, and the refusal
     * is how the model learns the budget is used.
     *
     * @return array<string, string>|string point statuses, or why it failed
     */
    private function finishAfterBudget(LeadBench $bench): array|string
    {
        for ($turn = 0; $turn < 2; ++$turn) {
            $calls = $this->play($bench);
            if (is_string($calls)) {
                return $calls;
            }
            $points = $this->finished($calls);
            if (null !== $points) {
                return $points;
            }
            foreach ($calls as $call) {
                if ('budget_used' !== $call['error']) {
                    return 'the model kept working when the budget was used';
                }
            }
        }

        return 'the model did not call finish when the budget was used';
    }

    private function finishesWhenCovered(LeadBench $bench): ?string
    {
        $this->covered($bench);
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }
        $points = $this->finished($calls);
        if (null === $points) {
            return 'the model kept working on a point two sources answer';
        }

        return 'answered' === ($points['p1'] ?? null) ? null : 'finish did not call p1 answered';
    }

    private function longResult(LeadBench $bench): ?string
    {
        for ($n = 1; $n <= 10; ++$n) {
            $bench->search->fallback[] = LeadBench::hit(
                'https://example.com/page-'.$n,
                str_repeat('Unit price overview '.$n.' ', 8),
                str_repeat('Prices, discounts and delivery terms for every unit and region. ', 6),
            );
        }
        $this->prior($bench, $this->search($bench, 'unit price'));
        $calls = $this->play($bench);

        return is_string($calls) ? $calls : null;
    }

    private function readsWhenSearchesUsed(LeadBench $bench): ?string
    {
        $id = $this->found($bench);
        $searches = $bench->ctx->state->budget->searchesLeft();
        if ($searches > 0) {
            $bench->ctx->state->budget->spend('searches', $searches);
        }
        $this->prior($bench, $this->search($bench, 'unit price list'));
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }
        if ($this->has($calls, 'search', null)) {
            return 'the model searched with no search left';
        }

        return $this->reads($calls, $id) ? null : 'the model did not read the source it still could';
    }

    private function movesToOpenPoint(LeadBench $bench): ?string
    {
        $bench->ctx->state->ledger->addPoint('Warranty period of the Maker X100', 'Needed.');
        $this->covered($bench);
        $calls = $this->play($bench);
        if (is_string($calls)) {
            return $calls;
        }
        if (null !== $this->finished($calls)) {
            return 'the model finished with p2 open and budget left';
        }
        if ($this->has($calls, 'search', 'p1') || $this->has($calls, 'read_sources', 'p1')) {
            return 'the model kept working on p1, which two sources answer';
        }

        return $this->has($calls, 'search', 'p2') || $this->has($calls, 'read_sources', 'p2')
            ? null
            : 'the model did not move on to p2';
    }

    private function finishNamesEveryPoint(LeadBench $bench): ?string
    {
        $bench->ctx->state->ledger->addPoint('Warranty period of the Maker X100', 'Needed.');
        $this->found($bench);
        $this->spendAll($bench);
        $this->prior($bench, $this->search($bench, 'unit price 2026'));
        $points = $this->finishAfterBudget($bench);
        if (is_string($points)) {
            return $points;
        }
        if (!isset($points['p1'], $points['p2'])) {
            return 'finish did not give every point a status';
        }

        return in_array('answered', $points, true) ? 'finish called a point answered that no source supports' : null;
    }

    /** What the model sent in the played turn, so a failure can be read without a debugger. */
    private function sent(LeadBench $bench): string
    {
        $turns = $bench->ctx->state->conversation->turns();
        $turn = $turns[count($turns) - 1] ?? null;
        if (null === $turn || $bench->ctx->state->status->isTerminal()) {
            return '';
        }
        $parts = [];
        foreach ($turn['calls'] as $wire) {
            $function = is_array($wire['function'] ?? null) ? $wire['function'] : [];
            $parts[] = (is_string($function['name'] ?? null) ? $function['name'] : '?')
                .(is_string($function['arguments'] ?? null) ? $function['arguments'] : '');
        }
        if ('' !== trim($turn['content'])) {
            $parts[] = 'text "'.trim($turn['content']).'"';
        }

        return [] === $parts ? '' : ' — sent '.mb_substr(implode(', ', $parts), 0, 300);
    }

    /**
     * Runs one lead turn and returns its calls, or why the turn failed.
     *
     * @return list<array{name: string, args: array<string, mixed>, error: ?string}>|string
     */
    private function play(LeadBench $bench): array|string
    {
        $before = count($bench->ctx->state->conversation->turns());
        if ('done' === $bench->stage->run($bench->ctx)->next) {
            return 'the run ended instead of a turn: '.($bench->ctx->state->outcome->code ?? 'no outcome');
        }
        $turn = $bench->ctx->state->conversation->turns()[$before] ?? null;
        if (null === $turn || [] === $turn['calls']) {
            return 'the model answered without a tool call';
        }
        $calls = [];
        foreach ($turn['calls'] as $index => $wire) {
            $function = is_array($wire['function'] ?? null) ? $wire['function'] : [];
            $args = json_decode(is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}', true);
            $body = json_decode($turn['results'][$index]['content'] ?? '', true);
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
            $name = is_string($function['name'] ?? null) ? $function['name'] : '';
            if (in_array($error, self::REFUSED, true)) {
                return 'the engine refused '.$name.' ('.$error.')';
            }
            $calls[] = ['name' => $name, 'args' => is_array($args) ? $args : [], 'error' => $error];
        }

        return $calls;
    }

    /**
     * @param list<array{name: string, args: array<string, mixed>, error: ?string}> $calls
     */
    private function has(array $calls, string $name, ?string $point): bool
    {
        foreach ($calls as $call) {
            if ($name === $call['name'] && (null === $point || $point === ($call['args']['point'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name: string, args: array<string, mixed>, error: ?string}> $calls
     */
    private function reads(array $calls, string $sourceId): bool
    {
        foreach ($calls as $call) {
            $ids = $call['args']['ids'] ?? null;
            if ('read_sources' === $call['name'] && is_array($ids) && in_array($sourceId, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name: string, args: array<string, mixed>, error: ?string}> $calls
     *
     * @return array<string, string>|null point id to status, or null without finish
     */
    private function finished(array $calls): ?array
    {
        foreach ($calls as $call) {
            if ('finish' !== $call['name']) {
                continue;
            }
            $points = [];
            foreach (is_array($call['args']['points'] ?? null) ? $call['args']['points'] : [] as $point) {
                if (is_array($point) && is_string($point['id'] ?? null) && is_string($point['status'] ?? null)) {
                    $points[$point['id']] = $point['status'];
                }
            }

            return $points;
        }

        return null;
    }

    /** One earlier search that found the price list. Returns its source id. */
    private function found(LeadBench $bench): string
    {
        $this->offer($bench, self::PRICE_URL);
        $bench->reader->fallback = LeadBench::evidence();
        $this->prior($bench, $this->search($bench, 'unit price'));

        return $bench->sourceId(self::PRICE_URL);
    }

    /** Two sources found and read, and both settle p1. */
    private function covered(LeadBench $bench): void
    {
        $this->offer($bench, self::PRICE_URL, self::SHOP_URL);
        $bench->reader->fallback = LeadBench::evidence();
        $this->prior($bench, $this->search($bench, 'unit price'));
        $ids = [$bench->sourceId(self::PRICE_URL), $bench->sourceId(self::SHOP_URL)];
        $this->prior($bench, new ToolCall('read_'.count($bench->ctx->state->conversation->turns()), 'read_sources', ['ids' => $ids, 'point' => 'p1']));
    }

    private function offer(LeadBench $bench, string ...$urls): void
    {
        foreach ($urls as $url) {
            $bench->search->fallback[] = LeadBench::hit($url, 'Maker X100 prices', 'Official price list for the Maker X100 and its accessories, updated 2026.');
            $bench->servePage($url);
        }
    }

    private function spendAll(LeadBench $bench): void
    {
        $budget = $bench->ctx->state->budget;
        if ($budget->searchesLeft() > 0) {
            $budget->spend('searches', $budget->searchesLeft());
        }
        if ($budget->sourcesLeft() > 0) {
            $budget->spend('sources', $budget->sourcesLeft());
        }
    }

    /** Executes calls on the bench and stores them as an earlier lead turn. */
    private function prior(LeadBench $bench, ToolCall ...$calls): void
    {
        $wires = [];
        $results = [];
        foreach ($calls as $call) {
            $wires[] = [
                'id' => $call->id,
                'type' => 'function',
                'function' => ['name' => $call->name, 'arguments' => json_encode($call->arguments, JSON_THROW_ON_ERROR)],
            ];
            $results[] = ['id' => $call->id, 'name' => $call->name, 'content' => $bench->tools->execute($call, $bench->ctx)->json];
        }
        $bench->ctx->state->conversation->append('', $wires, $results);
    }

    private function search(LeadBench $bench, string $query): ToolCall
    {
        $id = 'search_'.count($bench->ctx->state->conversation->turns());

        return new ToolCall($id, 'search', ['point' => 'p1', 'query' => $query, 'language' => 'en', 'scope' => 'web']);
    }
}
