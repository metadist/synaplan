<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchDecisionSchema;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\Loop\LoopAction;
use App\Service\Research\Run\Loop\LoopActions;
use App\Service\Research\Run\Loop\LoopRules;
use App\Service\Research\Run\Loop\LoopStop;
use App\Service\Research\Run\ResearchStatus;

/**
 * After a round, the lead judges the plan and names the next searches.
 * A quick run has no rounds, so it goes straight to the report.
 */
final readonly class ReflectStage implements ResearchStage
{
    public function __construct(
        private StageCall $calls,
        private LoopRules $rules = new LoopRules(),
        private LoopActions $actions = new LoopActions(),
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $progress = $this->cards($ctx) > $ctx->state->evidenceBeforeRound;
        $early = $this->rules->judge(
            $ctx->state->ledger,
            $ctx->state->budget,
            $ctx->stop->requested(),
            false,
            null,
            $progress,
            $ctx->state->strategyHint,
        );
        if (LoopStop::CONTINUE !== $early->next) {
            return $this->close($ctx, $early);
        }
        if ($ctx->state->budget->callsLeft() <= 1) {
            return new StageResult('write');
        }

        $called = $this->calls->once(
            $ctx,
            'planning',
            $ctx->roles->lead,
            $this->prompt($ctx, $early->hint),
            'Decide the next step.',
            ResearchDecisionSchema::build(),
        );
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $data = is_array($called->reply->data) ? $called->reply->data : [];
        $this->applyPoints($ctx, $data);
        $ctx->state->budget->spend('rounds');
        $finish = $this->actions->finish($data);
        if ('no_progress' === $finish && !$ctx->state->strategyHint) {
            $finish = null;
        }
        $decision = $this->rules->judge(
            $ctx->state->ledger,
            $ctx->state->budget,
            false,
            false,
            $finish,
            $progress,
            $ctx->state->strategyHint,
        );
        if (LoopStop::CONTINUE === $decision->next
            && !$this->queue($ctx, $this->actions->keep($data, $ctx->state->ledger, $ctx->state->budget, 0))) {
            $decision = new LoopStop(LoopStop::WRITE, 'no_search', null, false);
        }
        $this->roundEvent($ctx, $decision->next);
        if (LoopStop::CONTINUE !== $decision->next) {
            return $this->close($ctx, $decision);
        }

        $ctx->state->strategyHint = $early->hint && !$progress;
        if ($progress) {
            $ctx->state->strategyHint = false;
        }
        $ctx->state->advance(ResearchStatus::Gathering);

        return new StageResult('round');
    }

    private function close(StageContext $ctx, LoopStop $stop): StageResult
    {
        if (LoopStop::HALT === $stop->next && null !== $stop->outcome) {
            return StageEnds::finish($ctx, ResearchStatus::Stopped, $stop->outcome, 'stopped');
        }

        return new StageResult('write');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyPoints(StageContext $ctx, array $data): void
    {
        $points = $data['points'] ?? [];
        if (!is_array($points)) {
            return;
        }
        foreach ($points as $point) {
            if (!is_array($point) || !is_string($point['id'] ?? null) || !is_string($point['status'] ?? null)) {
                continue;
            }
            if (!in_array($point['status'], PlanPoint::STATUSES, true)) {
                continue;
            }
            try {
                $note = is_string($point['note'] ?? null) ? $point['note'] : '';
                $ctx->state->ledger->markPoint($point['id'], $point['status'], $note);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $ctx->events->append($ctx->state->ref->id, 'point', [
                'id' => $point['id'],
                'status' => $point['status'],
            ]);
        }
    }

    /**
     * @param list<LoopAction> $actions
     */
    private function queue(StageContext $ctx, array $actions): bool
    {
        $queued = false;
        foreach ($actions as $action) {
            if (LoopAction::SEARCH !== $action->type || null === $action->point || null === $action->query) {
                continue;
            }
            $queued = $ctx->state->ledger->planSearch(
                $action->point,
                $action->query,
                $action->language ?? 'en',
                $action->scope ?? 'web',
            ) || $queued;
        }

        return $queued;
    }

    private function prompt(StageContext $ctx, bool $hint): string
    {
        $budget = $ctx->state->budget->toArray();
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
        $exported = $ctx->state->budget->export();
        $limits = is_array($exported['limits'] ?? null) ? $exported['limits'] : [];
        $extras = is_array($limits['extras'] ?? null) ? $limits['extras'] : [];
        $maxActions = is_numeric($extras['max_actions'] ?? null) ? (string) $extras['max_actions'] : '6';

        return ResearchPrompts::fill('tools:research_reflect', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'ROUND' => (string) ($budget['rounds'][0] + 1),
            'MAX_ROUNDS' => (string) $budget['rounds'][1],
            'SEARCHES_LEFT' => (string) max(0, $budget['searches'][1] - $budget['searches'][0]),
            'READS_LEFT' => (string) $ctx->state->budget->sourcesLeft(),
            'MINUTES_LEFT' => (string) max(0, intdiv($ctx->state->budget->secondsLeft() + 59, 60)),
            'MAX_ACTIONS' => $maxActions,
            'PLAN' => implode("\n", $points),
            'LEDGER_SUMMARY' => $ledger->summaryForLead(4000),
            'QUERIES_RUN' => [] === $queries ? 'none' : implode("\n", $queries),
            'UNREAD_SOURCES' => [] === $unread ? 'none' : implode("\n", $unread),
            'HINT' => $hint ? 'The last round found nothing new. Change strategy: other languages, other source kinds, broader or narrower queries. Set finish to null and queue at least one new search.' : '',
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

    private function cards(StageContext $ctx): int
    {
        $evidence = $ctx->state->ledger->toArray()['evidence'] ?? [];

        return is_array($evidence) ? count($evidence) : 0;
    }
}
