<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Ledger\PlainText;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchNotes;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\Stage\DraftStream;
use App\Service\Research\Run\Stage\ResearchLeadCaller;
use App\Service\Research\Run\Stage\ResearchLeadTurn;
use App\Service\Research\Run\Stage\ResearchPrompts;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageEnds;
use App\Service\Research\Run\Stage\StageResult;

/**
 * One lead turn and the tool calls it made. The next round is another turn.
 * A turn with no tool call gets one reminder, and the turn after that goes
 * to the report. Finish ignores every other call in the same turn, unless
 * nothing was read yet: then finish is refused and the other calls run.
 */
final readonly class LeadStage
{
    private const REMINDER = 'Call a tool, or call finish with the status of every point. A turn without either ends the research.';

    /** A thought longer than this is cut; the person reads it in one glance. */
    private const THOUGHT_CHARS = 600;

    public function __construct(
        private ResearchLeadCaller $caller,
        private LeadToolbox $tools,
        private LeadModeResolver $modes,
        private ResearchInputBudget $budgets,
        private LeadPoints $points = new LeadPoints(),
    ) {
    }

    public function active(StageContext $ctx): bool
    {
        return LeadMode::Tools === $this->modes->resolve($ctx->roles->lead, $ctx->state->effectiveDepth());
    }

    public function run(StageContext $ctx): StageResult
    {
        $blocked = StageEnds::beforeCall($ctx);
        if ($blocked instanceof StageResult) {
            return $blocked;
        }
        if (!$ctx->state->budget->canRound() || $ctx->state->budget->callsLeft() <= 2) {
            return $this->toReport($ctx);
        }

        $thinking = DraftStream::for($ctx, 'thinking');
        $turn = $this->caller->turn(
            $ctx->state->ref->userId,
            $ctx->roles->lead,
            $this->messages($ctx),
            $this->tools->definitions($ctx),
            $thinking->listener(),
        );
        $thinking->finish();
        if ($turn->called) {
            $this->charge($ctx, $turn);
        }
        if (!$turn->called) {
            return StageEnds::roleUnavailable($ctx, 'planning');
        }
        if (!$turn->ok) {
            return StageEnds::model($ctx, 'planning');
        }

        $ctx->state->budget->spend('rounds');

        return [] === $turn->calls ? $this->noTools($ctx, $turn) : $this->withTools($ctx, $turn);
    }

    private function noTools(StageContext $ctx, ResearchLeadTurn $turn): StageResult
    {
        $this->thought($ctx, $turn);
        $this->remember($ctx, $turn, [], []);
        if ($ctx->state->conversation->wasReminded()) {
            $this->roundEvent($ctx, 'write');

            return $this->toReport($ctx);
        }
        $ctx->state->conversation->remind(self::REMINDER);

        return $this->again($ctx);
    }

    private function withTools(StageContext $ctx, ResearchLeadTurn $turn): StageResult
    {
        $this->thought($ctx, $turn);
        $finish = null;
        $rest = [];
        foreach ($turn->calls as $call) {
            if ('finish' === $call->name) {
                $finish = $call;
            } else {
                $rest[] = $call;
            }
        }
        if ($finish instanceof ToolCall && !$this->mustRead($ctx)) {
            $this->points->apply($ctx, $finish->arguments['points'] ?? null);
            $this->remember($ctx, $turn, [$this->wire($finish, 0)], [[
                'id' => $this->callId($finish, 0),
                'name' => 'finish',
                'content' => '{"finished":true}',
            ]]);
            $reason = $finish->arguments['reason'] ?? null;
            $this->roundEvent($ctx, 'write', is_string($reason) ? $reason : null);

            return $this->toReport($ctx);
        }

        $calls = $finish instanceof ToolCall ? $turn->calls : $rest;
        $wires = [];
        $results = [];
        $cap = $ctx->state->budget->extras()->maxActions;
        foreach ($calls as $index => $call) {
            $wires[] = $this->wire($call, $index);
            if ('finish' === $call->name || $index >= $cap) {
                $code = 'finish' === $call->name ? 'read_first' : 'not_allowed';
                $results[] = $this->result($call, $index, LeadToolReply::error($code, $ctx->state->budget));
                continue;
            }
            $outcome = $this->tools->execute(new ToolCall($this->callId($call, $index), $call->name, $call->arguments), $ctx);
            if ($outcome->stop instanceof StageResult && 'wait_answer' !== $outcome->stop->next) {
                return $outcome->stop;
            }
            $results[] = $this->result($call, $index, $outcome->json);
            if ($outcome->stop instanceof StageResult) {
                $this->closeTurn($ctx, $turn, $calls, $wires, $results, $index);

                return $outcome->stop;
            }
        }
        $this->remember($ctx, $turn, $wires, $results);
        if (!$ctx->state->budget->canRound()) {
            $this->roundEvent($ctx, 'write');

            return $this->toReport($ctx);
        }

        return $this->again($ctx);
    }

    /**
     * Search results are not evidence, so the report would have nothing to
     * cite. Finish is refused once while a found source can still be read;
     * after a read, even one that found nothing, finish is accepted.
     */
    private function mustRead(StageContext $ctx): bool
    {
        $budget = $ctx->state->budget;
        if (0 !== $ctx->state->ledger->evidenceCount() || 0 !== $budget->toArray()['sources_read'][0]) {
            return false;
        }
        if (!$budget->canRound() || $budget->sourcesLeft() < 1 || $budget->callsLeft() < 4) {
            return false;
        }
        foreach ($ctx->state->ledger->sources() as $source) {
            if (Source::FOUND === $source->status && (null !== $source->url || (Source::FILE === $source->kind && null !== $source->fileId))) {
                return true;
            }
        }

        return false;
    }

    private function again(StageContext $ctx): StageResult
    {
        $this->roundEvent($ctx, 'round');
        $ctx->state->advance(ResearchStatus::Gathering);

        return new StageResult('round');
    }

    private function toReport(StageContext $ctx): StageResult
    {
        if (!$ctx->state->status->isTerminal()) {
            $ctx->state->advance(ResearchStatus::Writing);
        }

        return new StageResult('write');
    }

    /**
     * @param list<array<string, mixed>>                             $calls
     * @param list<array{id: string, name: string, content: string}> $results
     */
    private function remember(StageContext $ctx, ResearchLeadTurn $turn, array $calls, array $results): void
    {
        $ctx->state->conversation->append($turn->text, $calls, $results);
        if ('' !== $turn->modelKey) {
            $ctx->state->ledger->setModel('lead', $turn->modelKey);
        }
    }

    private function charge(StageContext $ctx, ResearchLeadTurn $turn): void
    {
        $ctx->state->budget->spend('calls');
        if (null !== $turn->cost) {
            $ctx->state->budget->addCost($turn->cost);
        }
        if (null !== $turn->usage) {
            $ctx->state->spend->add('lead', $turn->modelKey, $turn->usage);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(StageContext $ctx): array
    {
        $summary = $ctx->state->ledger->summaryForLead(4000);

        return [
            ['role' => 'system', 'content' => $this->prompt($ctx)],
            ['role' => 'user', 'content' => $ctx->state->ref->question],
            ...$ctx->state->conversation->messagesFor($this->budgets->tokens('lead', null), ResearchPrompts::data($summary)),
        ];
    }

    private function prompt(StageContext $ctx): string
    {
        $budget = $ctx->state->budget;
        $brief = $ctx->state->ledger->brief();
        $points = [];
        foreach ($ctx->state->ledger->points() as $point) {
            $points[] = $point->id.' '.$point->status.' '.$point->title;
        }

        return ResearchPrompts::fill('tools:research_lead', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'DEPTH' => $ctx->state->ref->depth->value,
            'SEARCHES' => (string) $budget->searchesLeft(),
            'READS' => (string) $budget->sourcesLeft(),
            'MINUTES' => (string) intdiv($budget->secondsLeft() + 59, 60),
            'BRIEF' => ResearchPrompts::data(null !== $brief ? $brief->goal : $ctx->state->ref->question),
            'PLAN' => ResearchPrompts::data($this->planText($ctx, $points)),
        ]);
    }

    /**
     * @param list<string> $points
     */
    private function planText(StageContext $ctx, array $points): string
    {
        $plan = [] === $points ? 'none' : implode("\n", $points);
        $notes = ResearchNotes::text($ctx->state);

        return '' === $notes ? $plan : $plan."\n".$notes;
    }

    /**
     * A question ends the turn. Later calls in the same turn are not run,
     * and each one still gets a result so the provider accepts the turn.
     *
     * @param list<ToolCall>                                         $calls
     * @param list<array<string, mixed>>                             $wires
     * @param list<array{id: string, name: string, content: string}> $results
     */
    private function closeTurn(StageContext $ctx, ResearchLeadTurn $turn, array $calls, array $wires, array $results, int $asked): void
    {
        for ($after = $asked + 1, $afterMax = count($calls); $after < $afterMax; ++$after) {
            $wires[] = $this->wire($calls[$after], $after);
            $results[] = $this->result($calls[$after], $after, LeadToolReply::error('not_allowed', $ctx->state->budget));
        }
        $this->remember($ctx, $turn, $wires, $results);
        $this->roundEvent($ctx, 'wait_answer');
    }

    /**
     * @return array{id: string, name: string, content: string}
     */
    private function result(ToolCall $call, int $index, string $json): array
    {
        return [
            'id' => $this->callId($call, $index),
            'name' => $call->name,
            'content' => $json,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wire(ToolCall $call, int $index): array
    {
        return [
            'id' => $this->callId($call, $index),
            'type' => 'function',
            'function' => [
                'name' => $call->name,
                'arguments' => json_encode($call->arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    private function callId(ToolCall $call, int $index): string
    {
        return '' !== $call->id ? $call->id : 'call_'.$index;
    }

    /**
     * The words the lead wrote before its tool calls — what the last results
     * told it and what it does next. The person reads them while the run works.
     */
    private function thought(StageContext $ctx, ResearchLeadTurn $turn): void
    {
        $text = PlainText::line($turn->text, self::THOUGHT_CHARS);
        if ('' === $text) {
            return;
        }
        $ctx->events->append($ctx->state->ref->id, 'thought', ['text' => $text]);
    }

    private function roundEvent(StageContext $ctx, string $next, ?string $reason = null): void
    {
        $rounds = $ctx->state->budget->toArray()['rounds'];
        $event = [
            'n' => $rounds[0],
            'max' => $rounds[1],
            'next' => $next,
        ];
        if (null !== $reason && '' !== $reason) {
            $event['reason'] = $reason;
        }
        $ctx->events->append($ctx->state->ref->id, 'round', $event);
    }
}
