<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\EffortVerdict;

/**
 * Scores the sorter's effort rating against the labelled router corpus
 * (`tests/Eval/research/router.json`). The rater is passed in, so the
 * command runs the production sorter and the unit test a scripted one.
 *
 * Metrics (plan `01` §4.2): agreement, over-escalation (`research` on an
 * `answer` label), under-escalation (`answer` or `lookup` on a `research`
 * label), reason agreement, no-vote rate, and the confusion matrix.
 */
final readonly class RouterEval
{
    public const GATE_AGREEMENT = 0.90;
    public const GATE_OVER_ESCALATION = 0.05;
    public const GATE_UNDER_ESCALATION = 0.10;
    public const GATE_NO_VOTE = 0.02;

    /**
     * @param list<array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string}>                    $items
     * @param callable(array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string}): EffortVerdict $rate
     */
    public function run(array $items, callable $rate, int $repeat = 1): RouterEvalReport
    {
        $rows = [];
        foreach ($items as $item) {
            $votes = [];
            for ($i = 0; $i < max(1, $repeat); ++$i) {
                $votes[] = $rate($item);
            }
            $rows[] = new RouterEvalRow($item['id'], Effort::from($item['label']), $item['why'] ?? null, self::majority($votes));
        }

        return RouterEvalReport::fromRows($rows);
    }

    /**
     * @param list<EffortVerdict> $votes
     */
    private static function majority(array $votes): EffortVerdict
    {
        $counts = [];
        foreach ($votes as $vote) {
            $key = $vote->voted ? $vote->effort->value : 'none';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $winner = (string) array_key_first($counts);
        foreach ($votes as $vote) {
            if (($vote->voted ? $vote->effort->value : 'none') === $winner) {
                return $vote;
            }
        }

        return $votes[0];
    }
}
