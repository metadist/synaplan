<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchBriefSchema;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\ResearchQuestion;
use App\Service\Research\Run\ResearchQuestions;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;

/**
 * Turns the question into a brief. Questions the brief lists, up to the
 * depth's budget, are asked before the plan. The run waits with no worker.
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
        $language = self::language((string) ($data['language'] ?? ''));
        $output = (string) ($data['output'] ?? '');
        if (null === $language || !in_array($output, self::OUTPUTS, true)) {
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
        $ctx->events->append($ctx->state->ref->id, 'brief', [
            'goal' => $brief->goal,
            'language' => $brief->language,
            'output' => $brief->output,
            'time_frame' => $brief->timeFrame,
        ]);
        $questions = ResearchQuestion::collect(
            $data['ambiguity'] ?? [],
            $ctx->state->budget->extras()->questions - $ctx->state->questionsAsked,
        );
        if ([] !== $questions) {
            $ctx->state->questions = new ResearchQuestions($questions, ResearchUnitPlan::BRIEF_PLAN, ResearchStatus::Planning->value);
            $ctx->state->questionsAsked += count($questions);
            $ctx->state->advance(ResearchStatus::WaitingAnswer);
            foreach ($questions as $question) {
                $ctx->events->append($ctx->state->ref->id, 'question', $question->event());
            }

            return new StageResult('wait_answer');
        }
        $ctx->state->advance(ResearchStatus::Planning);

        return new StageResult('plan');
    }

    /** "de", "DE", "de-DE" and "de_AT" all mean German; anything else is not a language code. */
    private static function language(string $raw): ?string
    {
        if (1 !== preg_match('/^([a-z]{2})(?:[-_][a-z0-9]{2,8})?$/', strtolower(trim($raw)), $match)) {
            return null;
        }

        return $match[1];
    }
}
