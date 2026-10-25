<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchDecisionSchema;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Loop\LoopAction;
use App\Service\Research\Run\Loop\LoopActions;
use App\Service\Research\Run\Loop\LoopRules;
use App\Service\Research\Run\Loop\LoopStop;
use App\Service\Research\Run\ResearchNotes;
use App\Service\Research\Run\ResearchQuestion;
use App\Service\Research\Run\ResearchQuestions;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;

/**
 * After a round, the lead judges the plan and names the next searches.
 * On a quick run, after the last round, or when the budget could not pay
 * for another round and the report, the run goes straight to the report
 * without asking the lead, so what was found is never lost.
 */
final readonly class ReflectStage implements ResearchStage
{
    /** This call and its one retry, a triage and a read next round, then the writer. */
    private const CALLS_FOR_ANOTHER_ROUND = 5;

    /** @var list<string> */
    private const LEAD_STATUSES = [PlanPoint::ANSWERED, PlanPoint::THIN, PlanPoint::CONFLICTING, PlanPoint::OPEN];

    private const HINT = 'The last round found nothing new. Change strategy: other languages, other source kinds, broader or narrower queries. Set finish to null and queue at least one new search.';

    public function __construct(
        private StageCall $calls,
        private LoopRules $rules = new LoopRules(),
        private LoopActions $actions = new LoopActions(),
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $progress = $ctx->state->ledger->evidenceCount() > $ctx->state->evidenceBeforeRound;
        $early = $this->judge($ctx, $ctx->stop->requested(), null, $progress);
        if (LoopStop::CONTINUE !== $early->next) {
            return $this->close($ctx, $early);
        }
        if (!$this->canQueueRound($ctx)) {
            return new StageResult('write');
        }

        $called = $this->calls->once(
            $ctx,
            'planning',
            $ctx->roles->lead,
            $this->prompt($ctx, $early->hint),
            $this->request($ctx),
            ResearchDecisionSchema::build(),
        );
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $data = is_array($called->reply->data) ? $called->reply->data : [];
        $this->applyPoints($ctx, $data);
        $ctx->state->budget->spend('rounds');
        $finish = $this->actions->finish($data);
        // The first round without progress always gets one more round to change strategy.
        if ('no_progress' === $finish && !$ctx->state->strategyHint) {
            $finish = null;
            $data['finish'] = null;
        }
        $decision = $this->judge($ctx, false, $finish, $progress);
        if (LoopStop::CONTINUE === $decision->next) {
            $kept = $this->actions->keep($data, $ctx->state->ledger, $ctx->state->budget, $ctx->state->questionsAsked);
            $ask = $this->question($kept);
            $this->queue($ctx, $kept);
            if ($ask instanceof LoopAction && !$ctx->writeNow->requested() && $this->pose($ctx, $ask)) {
                $this->roundEvent($ctx, 'wait_answer');

                return new StageResult('wait_answer');
            }
            if (!$this->hasWork($ctx)) {
                $decision = new LoopStop(LoopStop::WRITE, 'nothing_queued', null, false);
            }
        }
        $this->roundEvent($ctx, $decision->next);
        if (LoopStop::CONTINUE !== $decision->next) {
            return $this->close($ctx, $decision);
        }

        $ctx->state->strategyHint = !$progress;
        $ctx->state->advance(ResearchStatus::Gathering);

        return new StageResult('round');
    }

    private function judge(StageContext $ctx, bool $stopped, ?string $finish, bool $progress): LoopStop
    {
        return $this->rules->judge(
            $ctx->state->ledger,
            $ctx->state->budget,
            $stopped,
            $ctx->writeNow->requested(),
            $finish,
            $progress,
            $ctx->state->strategyHint,
        );
    }

    /**
     * The round cap counts decisions, so the decision after the last round
     * that may run is not asked for.
     */
    private function canQueueRound(StageContext $ctx): bool
    {
        [$used, $cap] = $ctx->state->budget->toArray()['rounds'];

        return $used + 1 < $cap && $ctx->state->budget->callsLeft() >= self::CALLS_FOR_ANOTHER_ROUND;
    }

    /** A round with no new search and nothing left to read would only pay for another decision. */
    private function hasWork(StageContext $ctx): bool
    {
        $budget = $ctx->state->budget;
        if ($budget->canSearch() && $ctx->state->ledger->hasPlannedSearch()) {
            return true;
        }
        if (!$budget->canRead()) {
            return false;
        }
        foreach ($ctx->state->ledger->unreadSources() as $source) {
            if (Source::WEB === $source->kind && null !== $source->url) {
                return true;
            }
        }

        return false;
    }

    private function close(StageContext $ctx, LoopStop $stop): StageResult
    {
        if (LoopStop::HALT === $stop->next && null !== $stop->outcome) {
            return StageEnds::finish($ctx, ResearchStatus::Stopped, $stop->outcome, 'stopped');
        }
        if ($ctx->writeNow->requested() && !$ctx->state->status->isTerminal()) {
            $ctx->state->advance(ResearchStatus::Writing);
        }

        return new StageResult('write');
    }

    /**
     * @param list<LoopAction> $actions
     */
    private function question(array $actions): ?LoopAction
    {
        foreach ($actions as $action) {
            if (LoopAction::ASK_USER === $action->type) {
                return $action;
            }
        }

        return null;
    }

    private function pose(StageContext $ctx, LoopAction $action): bool
    {
        $question = ResearchQuestion::fromTool([
            'question' => $action->question ?? '',
            'options' => $action->options,
        ]);
        if (null === $question) {
            return false;
        }
        $ctx->state->questions = new ResearchQuestions([$question], ResearchUnitPlan::ROUND, ResearchStatus::Gathering->value);
        ++$ctx->state->questionsAsked;
        $ctx->state->advance(ResearchStatus::WaitingAnswer);
        $ctx->events->append($ctx->state->ref->id, 'question', $question->event());

        return true;
    }

    private function request(StageContext $ctx): string
    {
        $notes = ResearchNotes::text($ctx->state);

        return '' === $notes ? 'Decide the next step.' : "Decide the next step.\n".$notes;
    }

    /**
     * A point the lead calls answered without a single finding stays open:
     * the report cannot cite what was never read.
     *
     * @param array<string, mixed> $data
     */
    private function applyPoints(StageContext $ctx, array $data): void
    {
        $points = $data['points'] ?? [];
        if (!is_array($points)) {
            return;
        }
        $ledger = $ctx->state->ledger;
        foreach ($points as $point) {
            if (!is_array($point) || !is_string($point['id'] ?? null) || !is_string($point['status'] ?? null)) {
                continue;
            }
            $status = $point['status'];
            if (!in_array($status, self::LEAD_STATUSES, true)) {
                continue;
            }
            if (PlanPoint::ANSWERED === $status && !$ledger->hasEvidenceFor($point['id'])) {
                $status = PlanPoint::OPEN;
            }
            try {
                $note = is_string($point['note'] ?? null) ? $point['note'] : '';
                $ledger->markPoint($point['id'], $status, $note);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $ctx->events->append($ctx->state->ref->id, 'point', [
                'id' => $point['id'],
                'status' => $status,
            ]);
        }
    }

    /**
     * @param list<LoopAction> $actions
     */
    private function queue(StageContext $ctx, array $actions): void
    {
        $brief = $ctx->state->ledger->brief();
        $fallback = null !== $brief && '' !== $brief->language ? $brief->language : 'en';
        foreach ($actions as $action) {
            if (LoopAction::SEARCH !== $action->type || null === $action->point || null === $action->query) {
                continue;
            }
            $ctx->state->ledger->planSearch(
                $action->point,
                $action->query,
                $action->language ?? $fallback,
                $action->scope ?? 'web',
            );
        }
    }

    private function prompt(StageContext $ctx, bool $hint): string
    {
        $budget = $ctx->state->budget;
        $rounds = $budget->toArray()['rounds'];
        $ledger = $ctx->state->ledger;
        $queries = [];
        foreach ($ledger->queries() as $query) {
            $queries[] = $query->pointId.' '.$query->query;
        }
        $unread = [];
        foreach ($ledger->unreadSources() as $source) {
            $unread[] = $source->id.' '.$source->title;
        }
        $points = [];
        foreach ($ledger->points() as $point) {
            $points[] = $point->id.' '.$point->status.' '.$point->title;
        }

        return ResearchPrompts::fill('tools:research_reflect', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'ROUND' => (string) ($rounds[0] + 1),
            'MAX_ROUNDS' => (string) $rounds[1],
            'SEARCHES_LEFT' => (string) $budget->searchesLeft(),
            'READS_LEFT' => (string) $budget->sourcesLeft(),
            'MINUTES_LEFT' => (string) intdiv($budget->secondsLeft() + 59, 60),
            'MAX_ACTIONS' => (string) $budget->extras()->maxActions,
            'PLAN' => ResearchPrompts::data(implode("\n", $points)),
            'LEDGER_SUMMARY' => ResearchPrompts::data($ledger->summaryForLead(4000)),
            'QUERIES_RUN' => [] === $queries ? 'none' : ResearchPrompts::data(implode("\n", $queries)),
            'UNREAD_SOURCES' => [] === $unread ? 'none' : ResearchPrompts::data(implode("\n", $unread)),
            'HINT' => $hint ? self::HINT : '',
        ]);
    }

    private function roundEvent(StageContext $ctx, string $next): void
    {
        $rounds = $ctx->state->budget->toArray()['rounds'];
        $ctx->events->append($ctx->state->ref->id, 'round', [
            'n' => $rounds[0],
            'max' => $rounds[1],
            'next' => $next,
        ]);
    }
}
