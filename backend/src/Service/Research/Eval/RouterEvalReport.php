<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\Service\Message\Effort\Effort;

final readonly class RouterEvalReport
{
    /**
     * @param list<RouterEvalRow>               $rows
     * @param array<string, array<string, int>> $confusion     label → rated → count ("none" for no vote)
     * @param list<RouterEvalRow>               $disagreements
     */
    private function __construct(
        public array $rows,
        public float $agreement,
        public float $overEscalation,
        public float $underEscalation,
        public float $reasonAgreement,
        public float $noVote,
        public array $confusion,
        public array $disagreements,
    ) {
    }

    /**
     * @param list<RouterEvalRow> $rows
     */
    public static function fromRows(array $rows): self
    {
        $total = count($rows);
        $answerLabels = 0;
        $researchLabels = 0;
        $agree = 0;
        $over = 0;
        $under = 0;
        $reason = 0;
        $none = 0;
        $confusion = [];
        $disagreements = [];
        foreach (Effort::cases() as $label) {
            $confusion[$label->value] = ['answer' => 0, 'lookup' => 0, 'research' => 0, 'none' => 0];
        }
        foreach ($rows as $row) {
            $rated = $row->verdict->voted ? $row->verdict->effort->value : 'none';
            ++$confusion[$row->label->value][$rated];
            $agree += $row->agrees() ? 1 : 0;
            $reason += $row->reasonAgrees() ? 1 : 0;
            $none += $row->verdict->voted ? 0 : 1;
            if (Effort::Answer === $row->label) {
                ++$answerLabels;
                $over += $row->overEscalated() ? 1 : 0;
            }
            if (Effort::Research === $row->label) {
                ++$researchLabels;
                $under += $row->underEscalated() ? 1 : 0;
            }
            if (!$row->agrees()) {
                $disagreements[] = $row;
            }
        }

        return new self(
            $rows,
            self::share($agree, $total),
            self::share($over, $answerLabels),
            self::share($under, $researchLabels),
            self::share($reason, max(1, $agree)),
            self::share($none, $total),
            $confusion,
            $disagreements,
        );
    }

    public function meetsGate(): bool
    {
        return $this->agreement >= RouterEval::GATE_AGREEMENT
            && $this->overEscalation <= RouterEval::GATE_OVER_ESCALATION
            && $this->underEscalation <= RouterEval::GATE_UNDER_ESCALATION
            && $this->noVote <= RouterEval::GATE_NO_VOTE;
    }

    private static function share(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole, 4) : 0.0;
    }
}
