<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PlainText;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\ResearchStatus;

/**
 * What a run does when a model does not answer.
 *
 * The research still finishes. A missing brief is the question itself, a
 * missing plan is one search for that question, and a missing report is the
 * evidence already kept. A stop is not this path; a budget spent before
 * the writer could be paid is, for the report.
 */
final class ResearchCarryOn
{
    /** The sorting model did not return a brief. The question is the brief. */
    public function brief(StageContext $ctx): StageResult
    {
        $writing = $this->ensureBrief($ctx);
        if ($writing instanceof StageResult) {
            return $writing;
        }
        $ctx->state->advance(ResearchStatus::Planning);

        return new StageResult('plan');
    }

    /**
     * Stores a brief from the question when the run has none. Returns the
     * write-now result when the person already asked for the answer.
     */
    public function ensureBrief(StageContext $ctx): ?StageResult
    {
        $writing = StageEnds::writeNow($ctx);
        if ($writing instanceof StageResult) {
            return $writing;
        }
        if (null === $ctx->state->ledger->brief()) {
            $this->storeBrief($ctx);
        }

        return null;
    }

    /**
     * The planning model did not return points. One search for the question.
     * Returns write-now when that was asked; otherwise null and the caller
     * continues into gathering.
     */
    public function plan(StageContext $ctx): ?StageResult
    {
        $writing = $this->ensureBrief($ctx);
        if ($writing instanceof StageResult) {
            return $writing;
        }
        if ([] !== $ctx->state->ledger->points()) {
            return null;
        }

        $brief = $ctx->state->ledger->brief();
        $goal = null !== $brief && '' !== $brief->goal ? $brief->goal : $this->question($ctx);
        $language = null !== $brief && '' !== $brief->language ? $brief->language : 'en';
        $scope = $ctx->webOn() ? ($ctx->filesOn() ? 'both' : 'web') : 'files';
        $ctx->state->ledger->addPoint($goal, '', [[
            'q' => PlainText::line($goal, 200),
            'language' => $language,
            'scope' => $scope,
        ]]);
        if ([] === $ctx->state->ledger->outline()) {
            $ctx->state->ledger->setOutline(['Summary']);
        }

        return null;
    }

    /** The writing model did not answer. The evidence cards are the report. */
    public function report(StageContext $ctx): string
    {
        $lines = [];
        $brief = $ctx->state->ledger->brief();
        if (null !== $brief && '' !== trim($brief->goal)) {
            $lines[] = $brief->goal;
            $lines[] = '';
        }
        $grouped = EvidenceWindow::cards($ctx->state->ledger, $ctx->roles->checkerSameFamily);
        foreach ($ctx->state->ledger->points() as $point) {
            foreach ($grouped[$point->id] ?? [] as $card) {
                $fact = trim((string) ($card['fact'] ?? ''));
                $id = trim((string) ($card['id'] ?? ''));
                if ('' === $fact) {
                    continue;
                }
                $lines[] = '' === $id ? $fact : $fact.' ['.$id.']';
            }
        }

        return trim(implode("\n", $lines));
    }

    private function storeBrief(StageContext $ctx): void
    {
        $brief = new ResearchBrief($this->question($ctx), 'en', 'report');
        $ctx->state->ledger->setBrief($brief);
        $ctx->events->append($ctx->state->ref->id, 'brief', [
            'goal' => $brief->goal,
            'language' => $brief->language,
            'output' => $brief->output,
            'time_frame' => $brief->timeFrame,
        ]);
    }

    private function question(StageContext $ctx): string
    {
        $question = PlainText::line($ctx->state->ref->question, 4000);

        return '' === $question ? 'Research' : $question;
    }
}
