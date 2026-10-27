<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchStatus;

/**
 * Writes the report from evidence cards in one call. The writer never sees
 * the raw page, so a sentence in a page cannot become a sentence in the report
 * unless it was kept as evidence.
 */
final readonly class WriteStage implements ResearchStage
{
    public function __construct(private StageCall $calls)
    {
    }

    public function run(StageContext $ctx): StageResult
    {
        $prior = $ctx->state->priorReport;
        if ([] === $ctx->state->ledger->evidenceForWriter() && (null === $prior || '' === trim($prior))) {
            return new StageResult('check');
        }
        $brief = $ctx->state->ledger->brief();
        $language = null !== $brief ? $brief->language : 'en';
        $view = StageBudget::view($ctx->state->budget);
        $system = ResearchPrompts::fill('tools:research_write', [
            'OUTPUT' => null !== $brief ? $brief->output : 'report',
            'LANGUAGE' => $language,
            'AUDIENCE' => null !== $brief && null !== $brief->audience ? $brief->audience : 'the person asking',
            'NOT_CONFIRMED_TITLE' => CheckReport::notConfirmedTitle($language),
            'MAX_WORDS' => (string) $view['words'],
            'BRIEF' => null !== $brief ? $brief->goal : $ctx->state->ref->question,
            'OUTLINE' => implode("\n", $ctx->state->ledger->outline()),
            'EVIDENCE_BY_POINT' => $this->evidence($ctx),
        ]);
        $sections = $ctx->state->ledger->outline();
        if ([] === $sections) {
            $sections = ['Summary'];
        }
        $ctx->state->advance(ResearchStatus::Writing);
        $ctx->events->append($ctx->state->ref->id, 'writing', ['section' => $sections[0]]);

        $called = $this->calls->once($ctx, 'writing', $ctx->roles->writer, $system, FollowUpTask::text($ctx->state), null);
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $ctx->state->ledger->setReport($called->reply->text ?? '');
        $total = count($sections);
        foreach ($sections as $index => $section) {
            $ctx->events->append($ctx->state->ref->id, 'section', [
                'title' => $section,
                'index' => $index + 1,
                'total' => $total,
            ]);
        }

        return new StageResult('check');
    }

    private function evidence(StageContext $ctx): string
    {
        $lines = [];
        $grouped = $ctx->state->ledger->evidenceForWriter();
        foreach ($ctx->state->ledger->points() as $point) {
            $lines[] = $point->id.' '.$point->title;
            foreach ($grouped[$point->id] ?? [] as $card) {
                $quote = '';
                foreach (is_array($card['quotes'] ?? null) ? $card['quotes'] : [] as $row) {
                    if (is_array($row) && is_string($row['text'] ?? null)) {
                        $quote = ' quote: '.$row['text'];
                    }
                }
                $lines[] = '- '.$card['id'].' '.$card['fact'].$quote;
            }
        }

        return [] === $lines ? 'none' : implode("\n", $lines);
    }
}
