<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchPlanSchema;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchNotes;
use App\Service\Research\Run\ResearchStatus;

/**
 * Builds the plan. A thorough run waits for the person to confirm it, with
 * no worker held. A shorter run starts gathering at once.
 */
final readonly class PlanStage implements ResearchStage
{
    public function __construct(
        private StageCall $calls,
        private AutoCeiling $ceiling = new AutoCeiling(),
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $brief = $ctx->state->ledger->brief();
        if (null === $brief) {
            return StageEnds::model($ctx, 'planning');
        }
        $view = StageBudget::view($ctx->state->budget);
        [$view['pointsMin'], $view['pointsMax']] = $this->ceiling->pointsRange($ctx->state, $view['pointsMin'], $view['pointsMax']);
        $system = ResearchPrompts::fill('tools:research_plan', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'LANGUAGE' => $brief->language,
            'DEPTH' => $ctx->state->effectiveDepth()->value,
            'POINTS_MIN' => (string) $view['pointsMin'],
            'POINTS_MAX' => (string) $view['pointsMax'],
            'SOURCES' => $ctx->sourcesOn,
            'RULES' => $ctx->rules,
            'BRIEF' => $brief->goal,
            'FILE_CARDS' => 'none',
            'OUTPUT' => $brief->output,
        ]);
        $called = $this->calls->once(
            $ctx,
            'planning',
            $ctx->roles->lead,
            $system,
            $this->request($ctx),
            ResearchPlanSchema::build(),
        );
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $data = $called->reply->data ?? [];
        $rows = $data['points'] ?? null;
        if (!is_array($rows)) {
            return StageEnds::model($ctx, 'planning');
        }
        $kept = 0;
        foreach ($rows as $row) {
            if ($kept >= $view['pointsMax'] || !is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ('' === $title) {
                continue;
            }
            $scope = (string) ($row['scope'] ?? 'web');
            $queries = [];
            foreach (is_array($row['queries'] ?? null) ? $row['queries'] : [] as $query) {
                if (!is_array($query) || '' === trim((string) ($query['q'] ?? ''))) {
                    continue;
                }
                $queries[] = [
                    'q' => (string) $query['q'],
                    'language' => (string) ($query['language'] ?? $brief->language),
                    'scope' => $scope,
                ];
            }
            if ([] === $queries) {
                $queries[] = ['q' => $title, 'language' => $brief->language, 'scope' => $scope];
            }
            $ctx->state->ledger->addPoint($title, (string) ($row['why'] ?? ''), $queries);
            ++$kept;
        }
        if (0 === $kept) {
            return StageEnds::model($ctx, 'planning');
        }

        $ctx->state->ledger->setOutline(array_values(array_filter(
            is_array($data['outline'] ?? null) ? $data['outline'] : [],
            static fn (mixed $section): bool => is_string($section),
        )));
        $this->announcePlan($ctx, $kept, $view['minutes']);
        $writing = StageEnds::writeNow($ctx);
        if ($writing instanceof StageResult) {
            return $writing;
        }
        if (ResearchDepth::Thorough === $ctx->state->ref->depth) {
            $ctx->state->advance(ResearchStatus::WaitingPlan);
            $ctx->events->append($ctx->state->ref->id, 'plan_waiting', []);

            return new StageResult('wait_plan');
        }
        $ctx->state->advance(ResearchStatus::Gathering);

        return new StageResult('gather');
    }

    /**
     * The plan event: the points, the outline, and — for an automatic run —
     * the ceiling the plan chose and the minutes it now needs.
     */
    private function announcePlan(StageContext $ctx, int $kept, int $minutes): void
    {
        $ceiling = $this->ceiling->apply($ctx->state, $kept);
        $ctx->events->append($ctx->state->ref->id, 'plan', [
            'points' => array_map(static fn (PlanPoint $point): array => [
                'id' => $point->id,
                'title' => $point->title,
            ], $ctx->state->ledger->points()),
            'outline' => $ctx->state->ledger->outline(),
            'estimate' => [
                'minutes' => null === $ceiling ? $minutes : $ctx->state->budget->limits()->minutes(),
                'cost' => $ctx->state->budget->toArray()['cost']['estimate'],
            ],
            'ceiling' => $ceiling?->value,
        ]);
    }

    private function request(StageContext $ctx): string
    {
        $notes = ResearchNotes::text($ctx->state);

        return '' === $notes ? 'Plan the research.' : "Plan the research.\n".$notes;
    }
}
