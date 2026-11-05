<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchStatus;

/**
 * Writes the report from evidence cards in one call. The writer never sees
 * the raw page, so a sentence in a page cannot become a sentence in the report
 * unless it was kept as evidence. The words go out as `draft` events while
 * the model writes them, so the person reads the answer forming before the
 * fact-check settles it.
 */
final readonly class WriteStage implements ResearchStage
{
    public function __construct(private StageCall $calls)
    {
    }

    public function run(StageContext $ctx): StageResult
    {
        // However the run arrived here, a spent limit is named in the end.
        $ctx->state->noteLimitHit();
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

        $draft = DraftStream::for($ctx);
        $called = $this->calls->once($ctx, 'writing', $ctx->roles->writer, $system, FollowUpTask::text($ctx->state), null, $draft->listener());
        $draft->finish();
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        $text = trim($called->reply->text);
        if ('' === $text) {
            $text = (new ResearchCarryOn())->report($ctx);
        }
        if ('' === $text) {
            return new StageResult('check');
        }
        $ctx->state->ledger->setReport($text);
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
        $gaps = [];
        $grouped = EvidenceWindow::cards($ctx->state->ledger, $ctx->roles->checkerSameFamily);
        foreach ($ctx->state->ledger->points() as $point) {
            $cards = $grouped[$point->id] ?? [];
            if ([] === $cards) {
                $gaps[] = $point->title;
                continue;
            }
            $lines[] = $point->title;
            foreach ($cards as $card) {
                $quote = '';
                foreach (is_array($card['quotes'] ?? null) ? $card['quotes'] : [] as $row) {
                    if (is_array($row) && is_string($row['text'] ?? null)) {
                        $quote = ' quote: '.$row['text'];
                    }
                }
                $lines[] = '- '.$card['id'].' '.$card['fact'].$quote;
            }
        }
        if ([] !== $gaps) {
            $lines[] = 'Gaps (missing — name each as missing, do not write a claim):';
            foreach ($gaps as $title) {
                $lines[] = '- '.$title;
            }
        }

        return [] === $lines ? 'none' : implode("\n", $lines);
    }
}
