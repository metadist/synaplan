<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchBriefSchema;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\ResearchStatus;

/**
 * Turns the question into a brief. Questions in the answer are not asked yet:
 * the run goes on to the plan.
 */
final readonly class BriefStage implements ResearchStage
{
    /** @var list<string> */
    private const OUTPUTS = ['answer', 'report', 'table', 'comparison', 'list'];

    public function __construct(private StageCall $calls)
    {
    }

    public function run(StageContext $ctx): StageResult
    {
        $system = ResearchPrompts::fill('tools:research_brief', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'TIMEZONE' => $ctx->timezone,
            'SOURCES' => $ctx->sourcesOn,
            'RULES' => $ctx->rules,
            'TURNS' => $ctx->turns,
        ]);
        $called = $this->calls->once(
            $ctx,
            'sorting',
            $ctx->roles->sorter,
            $system,
            $ctx->state->ref->question,
            ResearchBriefSchema::build(),
        );
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $data = $called->reply->data ?? [];
        $language = strtolower(trim((string) ($data['language'] ?? '')));
        $output = (string) ($data['output'] ?? '');
        if (1 !== preg_match('/^[a-z]{2}$/', $language) || !in_array($output, self::OUTPUTS, true)) {
            return StageEnds::model($ctx, 'sorting');
        }

        try {
            $brief = ResearchBrief::fromArray([
                'goal' => $data['goal'] ?? '',
                'language' => $language,
                'output' => $output,
                'audience' => $data['audience'] ?? null,
                'time_frame' => is_array($data['time_frame'] ?? null) ? $data['time_frame'] : null,
                'region' => $data['region'] ?? null,
                'must_use' => $data['must_use'] ?? [],
                'must_avoid' => $data['must_avoid'] ?? [],
            ]);
        } catch (\InvalidArgumentException) {
            return StageEnds::model($ctx, 'sorting');
        }

        $ctx->state->ledger->setBrief($brief);
        $ctx->state->advance(ResearchStatus::Planning);
        $ctx->events->append($ctx->state->ref->id, 'brief', [
            'goal' => $brief->goal,
            'language' => $brief->language,
            'output' => $brief->output,
        ]);

        return new StageResult('plan');
    }
}
